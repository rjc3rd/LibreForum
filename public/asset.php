<?php
// Serves the active theme's CSS, JS, images and fonts (themes/ lives outside the web root).

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/theme.php';

$types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'woff2' => 'font/woff2', 'ico' => 'image/x-icon'];
$file = (string) ($_GET['f'] ?? '');
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$path = preg_match('~^[a-z0-9][a-z0-9._-]*$~iD', $file) && isset($types[$ext]) ? lf_theme_file('assets', $file) : null;
if ($path === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $types[$ext] . ($ext === 'css' || $ext === 'js' || $ext === 'svg' ? '; charset=utf-8' : ''));
header('Cache-Control: public, max-age=' . (isset($_GET['v']) ? 31536000 : 3600));
header('X-Content-Type-Options: nosniff');
readfile($path);
