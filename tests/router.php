<?php
// Router for PHP's built-in web server, used by tests/e2e.php. It points LibreForum at the e2e database,
// then behaves like the real .htaccess: asset.php is a file, everything else is a page.
//   php -S 127.0.0.1:8123 -t public tests/router.php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
$t = require __DIR__ . '/config.php';
if (!str_ends_with((string) $t['e2e_db'], '_e2e')) {
    http_response_code(500);
    exit('tests/router.php only works with a database whose name ends in _e2e.');
}
lf_config([
    'db' => ['host' => $t['host'], 'name' => $t['e2e_db'], 'user' => $t['user'], 'pass' => $t['pass']],
    'name' => 'E2E Forum', 'reserved_names' => ['admin', 'staff'],
    'limits' => ['posts_per_hour' => 1000, 'threads_per_day' => 1000, 'seconds_between_posts' => 0],
    'session_days' => 30, 'trusted_proxies' => [], 'theme' => 'default', 'theme_paths' => [],
    'threads_per_page' => 3, 'posts_per_page' => 3,
]);

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/asset.php') {
    $_SERVER['SCRIPT_NAME'] = '/asset.php';
    require __DIR__ . '/../public/asset.php';
} else {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require __DIR__ . '/../public/index.php';
}
