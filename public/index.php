<?php
// The front controller: every page of the forum comes through here (see .htaccess).

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
foreach (['http', 'theme', 'text', 'salt', 'auth', 'members', 'perm', 'setup', 'forum', 'host', 'pages'] as $lib) {
    require_once __DIR__ . "/../lib/$lib.php";
}

lf_send_headers();
try {
    lf_dispatch(lf_db(), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), lf_route_path());
} catch (Throwable $e) {
    // Details go to the server's error log, never to the visitor.
    error_log('LibreForum: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong</title>',
        '<h1>Something went wrong</h1><p>The forum hit a problem. Please try again in a moment.</p>';
}
