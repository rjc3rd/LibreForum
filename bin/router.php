<?php
// For trying LibreForum on your own computer with PHP's built-in web server (not for a live site):
//   php -S 127.0.0.1:8080 -t public bin/router.php
// then open http://127.0.0.1:8080

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    exit("This file is for PHP's built-in web server. See the comment at the top.\n");
}
if (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/asset.php') {
    return false;   // the server runs asset.php itself
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../public/index.php';
