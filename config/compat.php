<?php
/**
 * PHP version compatibility shims.
 * Lets the app run on older local PHP (e.g. AppServ with PHP 7.x)
 * as well as modern PHP 8 hosts. Safe to include multiple times.
 */

if (!function_exists('str_contains')) {
    /** PHP 8 polyfill. */
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    /** PHP 8 polyfill. */
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    /** PHP 8 polyfill. */
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}
