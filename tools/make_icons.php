<?php
// One-off icon generator: outputs committed PNGs into assets/icons/.
if (PHP_SAPI !== 'cli') { exit('CLI only'); }

function make_icon(int $size, string $path, bool $maskable): void
{
    $im = imagecreatetruecolor($size, $size);

    // Vertical gradient background #0d8a80 -> #0b6e6e
    $top = [13, 138, 128];
    $bot = [11, 110, 110];
    for ($y = 0; $y < $size; $y++) {
        $t = $y / max(1, $size - 1);
        $c = imagecolorallocate(
            $im,
            (int) round($top[0] + ($bot[0] - $top[0]) * $t),
            (int) round($top[1] + ($bot[1] - $top[1]) * $t),
            (int) round($top[2] + ($bot[2] - $top[2]) * $t)
        );
        imageline($im, 0, $y, $size - 1, $y, $c);
    }

    // Heart + pulse line ("heart-pulse") in white, centered in the safe area.
    $white = imagecolorallocate($im, 255, 255, 255);
    $s = $size / 100.0;                       // scale factor on a 100-unit canvas
    $cx = 50 * $s;
    $cy = $maskable ? 47 * $s : 50 * $s;      // maskable keeps more margin
    $scale = $maskable ? 30 : 34;             // heart half-width in canvas units

    // Heart shape from two circles + triangle.
    $r = $scale * 0.40;
    $dy = $scale * 0.30;
    imagefilledellipse($im, (int) ($cx - $scale / 2.0), (int) ($cy - $dy), (int) ($r * 2), (int) ($r * 2), $white);
    imagefilledellipse($im, (int) ($cx + $scale / 2.0), (int) ($cy - $dy), (int) ($r * 2), (int) ($r * 2), $white);
    $tri = [
        $cx - $scale * 0.92, $cy - $dy * 0.55,
        $cx + $scale * 0.92, $cy - $dy * 0.55,
        $cx, $cy + $scale * 1.02,
    ];
    imagefilledpolygon($im, $tri, $white);

    // Pulse line across the heart (brand-dark cutout).
    $cut = imagecolorallocate($im, 11, 110, 110);
    $lw = max(2, (int) round(4.5 * $s));
    $pts = [
        [-34, 2], [-16, 2], [-10, -8], [-2, 10], [6, -4], [12, 2], [34, 2],
    ];
    for ($i = 0; $i < count($pts) - 1; $i++) {
        imageline(
            $im,
            (int) ($cx + $pts[$i][0] * $s * ($scale / 34)),
            (int) ($cy + $pts[$i][1] * $s * ($scale / 34)),
            (int) ($cx + $pts[$i + 1][0] * $s * ($scale / 34)),
            (int) ($cy + $pts[$i + 1][1] * $s * ($scale / 34)),
            $cut
        );
    }

    // Rounded corners for the non-maskable variants (transparent outside).
    if (!$maskable) {
        $corner = (int) round($size * 0.16);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        for ($y = 0; $y < $corner; $y++) {
            $w = (int) floor($corner - sqrt(max(0, $corner * $corner - ($corner - $y) ** 2)));
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, 0, 0, 0, 127));
                imagesetpixel($im, $size - 1 - $x, $y, imagecolorallocatealpha($im, 0, 0, 0, 127));
                imagesetpixel($im, $x, $size - 1 - $y, imagecolorallocatealpha($im, 0, 0, 0, 127));
                imagesetpixel($im, $size - 1 - $x, $size - 1 - $y, imagecolorallocatealpha($im, 0, 0, 0, 127));
            }
        }
    }

    imagepng($im, $path, 6);
    imagedestroy($im);
    echo "wrote $path\n";
}

if (!is_dir(__DIR__ . '/../assets/icons')) {
    mkdir(__DIR__ . '/../assets/icons', 0755, true);
}
make_icon(192, __DIR__ . '/../assets/icons/icon-192.png', false);
make_icon(512, __DIR__ . '/../assets/icons/icon-512.png', false);
make_icon(192, __DIR__ . '/../assets/icons/icon-maskable-192.png', true);
make_icon(512, __DIR__ . '/../assets/icons/icon-maskable-512.png', true);
echo "done\n";
