<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

// PHP 7.4 polyfills
if (!function_exists('str_contains'))    { function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; } }
if (!function_exists('str_starts_with')) { function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; } }
if (!function_exists('str_ends_with'))   { function str_ends_with($h, $n) { return $n === '' || substr($h, -strlen($n)) === $n; } }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Tmdb.php';
require_once __DIR__ . '/helpers.php';

if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
$tmdb = new Tmdb();
