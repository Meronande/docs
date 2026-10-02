<?php
/**
 * Supabase integration helpers (REST + Storage over cURL — no SDK required).
 *
 * Configuration comes from the environment (Freebuff Keys tab / .env):
 *   SUPABASE_URL               Project URL, e.g. https://abcdefgh.supabase.co
 *   SUPABASE_ANON_KEY          Public anon key (used for health checks)
 *   SUPABASE_SERVICE_ROLE_KEY  Secret key — required to upload/list backups
 *   SUPABASE_PROJECT_REF       Optional project reference (informational)
 *
 * The clinic app itself keeps running on MySQL; Supabase is used for
 * offsite backups. Every call degrades gracefully when keys are missing —
 * the application never breaks because Supabase is absent or unreachable.
 */

declare(strict_types=1);

/** Supabase project URL without trailing slash ('' when not configured). */
function supabase_url(): string
{
    $u = getenv('SUPABASE_URL');
    if ($u === false || trim((string) $u) === '') {
        return '';
    }
    return rtrim(trim((string) $u), '/');
}

/** Read a Supabase key from the environment ('anon' or 'service'). */
function supabase_key(string $which = 'anon'): string
{
    $name = $which === 'service' ? 'SUPABASE_SERVICE_ROLE_KEY' : 'SUPABASE_ANON_KEY';
    $k = getenv($name);
    return $k === false ? '' : trim((string) $k);
}

/** True when the minimum configuration (URL + anon key) is present. */
function supabase_enabled(): bool
{
    return supabase_url() !== '' && supabase_key('anon') !== '';
}

/** Storage bucket that receives database backups. */
function supabase_backup_bucket(): string
{
    return 'clinic-backups';
}

/**
 * Generic Supabase REST call.
 * $body: array  -> JSON-encoded with Content-Type: application/json
 *         string -> sent raw (supply your own Content-Type in $extraHeaders)
 * Returns ['ok' => bool, 'status' => int, 'message' => string, 'data' => mixed]
 */
function supabase_request(string $method, string $path, $body = null, array $extraHeaders = [], bool $useServiceKey = false): array
{
    $base = supabase_url();
    if ($base === '') {
        return ['ok' => false, 'status' => 0, 'message' => 'SUPABASE_URL is not set in the environment.', 'data' => null];
    }
    $which = $useServiceKey ? 'service' : 'anon';
    $key = supabase_key($which);
    if ($key === '') {
        return ['ok' => false, 'status' => 0, 'message' => ($which === 'service' ? 'SUPABASE_SERVICE_ROLE_KEY' : 'SUPABASE_ANON_KEY') . ' is not set in the environment.', 'data' => null];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'message' => 'The PHP cURL extension is required for the Supabase integration.', 'data' => null];
    }

    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
    ];
    $payload = null;
    if (is_array($body)) {
        $headers[] = 'Content-Type: application/json';
        $payload = json_encode($body);
    } elseif (is_string($body) && $body !== '') {
        $payload = $body;
    }
    foreach ($extraHeaders as $h) {
        $headers[] = $h;
    }

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'message' => 'Connection to Supabase failed: ' . $err, 'data' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $json = json_decode((string) $resp, true);
    $ok = $status >= 200 && $status < 300;
    $message = 'OK';
    if (!$ok) {
        $message = 'Supabase returned HTTP ' . $status;
        if (is_array($json)) {
            if (!empty($json['message']) && is_string($json['message'])) {
                $message .= ': ' . $json['message'];
            } elseif (!empty($json['error']) && is_string($json['error'])) {
                $message .= ': ' . $json['error'];
            }
        }
    }
    return ['ok' => $ok, 'status' => $status, 'message' => $message, 'data' => $json];
}

/**
 * Health check against the configured project (validates the keys).
 * Returns ['ok', 'configured', 'status'?, 'message'].
 */
function supabase_health(): array
{
    if (!supabase_enabled()) {
        return [
            'ok' => false,
            'configured' => false,
            'message' => 'Supabase is not configured yet. Add SUPABASE_URL and SUPABASE_ANON_KEY (plus SUPABASE_SERVICE_ROLE_KEY for backups) in the Keys tab.',
        ];
    }
    $r = supabase_request('GET', '/rest/v1/');
    return [
        'ok' => !empty($r['ok']),
        'configured' => true,
        'status' => $r['status'],
        'message' => $r['ok'] ? 'Connected — Supabase API keys accepted.' : $r['message'],
    ];
}

/** URL-encode each segment of a storage object path. */
function supabase_storage_object_path(string $objectPath): string
{
    $parts = explode('/', $objectPath);
    $enc = [];
    foreach ($parts as $p) {
        $enc[] = rawurlencode($p);
    }
    return implode('/', $enc);
}

/**
 * Make sure a storage bucket exists (creates a private one when missing).
 * Requires the service key. Returns ['ok' => bool, 'created' => bool] or ['ok' => false, 'message'].
 */
function supabase_storage_ensure_bucket(string $bucket): array
{
    $r = supabase_request('GET', '/storage/v1/bucket/' . rawurlencode($bucket), null, [], true);
    if (!empty($r['ok'])) {
        return ['ok' => true, 'created' => false];
    }
    $c = supabase_request('POST', '/storage/v1/bucket', ['name' => $bucket, 'public' => false], [], true);
    if (!empty($c['ok'])) {
        return ['ok' => true, 'created' => true];
    }
    // Concurrent create / pre-existing bucket: treat "already exists" as success.
    $body = json_encode($c['data']);
    if ($c['status'] === 400 && is_string($body) && stripos($body, 'exist') !== false) {
        return ['ok' => true, 'created' => false];
    }
    return ['ok' => false, 'message' => $c['message']];
}

/**
 * Upload raw bytes to Storage as {bucket}/{objectPath} (service key, upsert).
 * Returns ['ok' => bool, 'message' => string, 'object' => 'bucket/path'].
 */
function supabase_storage_upload(string $bucket, string $objectPath, string $bytes): array
{
    $r = supabase_request(
        'POST',
        '/storage/v1/object/' . rawurlencode($bucket) . '/' . supabase_storage_object_path($objectPath),
        $bytes,
        ['Content-Type: application/octet-stream', 'x-upsert: true'],
        true
    );
    return [
        'ok' => !empty($r['ok']),
        'message' => $r['ok'] ? 'Uploaded.' : $r['message'],
        'object' => $bucket . '/' . $objectPath,
    ];
}

/**
 * List objects under a prefix in a bucket (service key).
 * Returns ['ok' => bool, 'message' => string, 'files' => [['name','size','created'], ...]].
 */
function supabase_storage_list(string $bucket, string $prefix = ''): array
{
    $r = supabase_request('POST', '/storage/v1/object/list/' . rawurlencode($bucket), [
        'prefix' => $prefix,
        'limit' => 100,
        'offset' => 0,
        'sortBy' => ['column' => 'created_at', 'order' => 'desc'],
    ], [], true);
    if (empty($r['ok'])) {
        return ['ok' => false, 'message' => $r['message'], 'files' => []];
    }
    $files = [];
    foreach ((array) ($r['data'] ?: []) as $f) {
        if (!is_array($f) || empty($f['name']) || !is_string($f['name'])) {
            continue;
        }
        $files[] = [
            'name' => $f['name'],
            'size' => (int) (isset($f['metadata']['size']) ? $f['metadata']['size'] : 0),
            'created' => (string) (isset($f['created_at']) ? $f['created_at'] : ''),
        ];
    }
    return ['ok' => true, 'message' => 'OK', 'files' => $files];
}
