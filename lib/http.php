<?php
// Web helpers: the folder the forum is served from, addresses, redirects, the login cookie, the
// protections every page carries (security headers, a check that forms come from this site) and times.

declare(strict_types=1);

const LF_COOKIE = 'libreforum';

// The folder LibreForum is served from: '' at the top of a domain, '/forum' in a sub-folder. Taken from
// the script's own address, so it works wherever the folder is put.
function lf_base(): string
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
}

// An address inside the forum: lf_url('t/12') is /t/12, or /forum/t/12 in a sub-folder.
function lf_url(string $path = ''): string
{
    return lf_base() . '/' . ltrim($path, '/');
}

// The page being asked for, without the folder and the query string: '/', '/t/12', '/c/help'.
function lf_route_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $base = lf_base();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    return '/' . trim($path, '/');
}

// Sends the browser to another page of the forum (never to another site).
function lf_redirect(string $path): never
{
    header('Location: ' . lf_url($path), true, 303);
    exit;
}

function lf_is_https(?array $server = null): bool
{
    $server ??= $_SERVER;
    $https = strtolower((string) ($server['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    // Behind a reverse proxy we trust that ends the HTTPS connection.
    $proxies = (array) lf_cfg('trusted_proxies', []);
    return $proxies !== [] && in_array($server['REMOTE_ADDR'] ?? '', $proxies, true)
        && strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function lf_cookie_set(string $token): void
{
    $base = lf_base();
    setcookie(LF_COOKIE, $token, [
        'expires' => time() + (int) lf_cfg('session_days', 30) * 86400,
        'path' => $base === '' ? '/' : $base,
        'secure' => lf_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function lf_cookie_clear(): void
{
    $base = lf_base();
    setcookie(LF_COOKIE, '', ['expires' => 1, 'path' => $base === '' ? '/' : $base, 'secure' => lf_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

// Refuses a form that was sent from another website. Browsers say where a form came from (the Origin
// header, or Sec-Fetch-Site), so a page somewhere else can't make a visitor's browser post here.
// Programs that send neither header (curl, the tests) are let through: only a browser can be tricked.
function lf_origin_ok(array $server): bool
{
    $origin = $server['HTTP_ORIGIN'] ?? null;
    if ($origin !== null) {
        $parts = parse_url((string) $origin);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;   // "null": a sandboxed or redirected page
        }
        $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $host === strtolower((string) ($server['HTTP_HOST'] ?? ''));
    }
    $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
    return $site === null || $site === 'same-origin' || $site === 'none';
}

// Headers for every page: nothing from outside is ever loaded, nothing is cached, nothing is indexed.
function lf_send_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    // Other websites never learn where a visitor came from. Same-site forms still say who sent them.
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
}

// "5 min ago", "3 hours ago", or the date once it is more than a week old. $utc is a database time.
function lf_ago(string $utc, ?int $now = null): string
{
    $then = strtotime($utc . ' UTC');
    $now ??= time();
    $diff = $now - $then;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . ' min ago';
    }
    if ($diff < 86400) {
        $n = intdiv($diff, 3600);
        return $n . ($n === 1 ? ' hour ago' : ' hours ago');
    }
    if ($diff < 7 * 86400) {
        $n = intdiv($diff, 86400);
        return $n . ($n === 1 ? ' day ago' : ' days ago');
    }
    return gmdate(gmdate('Y', $then) === gmdate('Y', $now) ? 'M j' : 'M j, Y', $then);
}

// A database time as the machine-readable value of a <time> element.
function lf_iso(string $utc): string
{
    return gmdate('Y-m-d\TH:i:s\Z', strtotime($utc . ' UTC'));
}
