<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (is_string($path) && preg_match('~^/assets/(?:[a-z0-9_-]+/)*[a-zA-Z0-9_.-]+\.(?:css|js|png|woff2)$~D', $path)) {
    $asset = realpath(__DIR__ . $path);
    $base = realpath(__DIR__ . '/assets');
    if ($asset && $base && str_starts_with($asset, $base . DIRECTORY_SEPARATOR) && is_file($asset)) return false;
}
require __DIR__.'/index.php';
