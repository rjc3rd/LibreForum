<?php
// Logging in. A login is a random token in one cookie. The database keeps only the token's hash, so a
// copy of the database can't be used to get in. Wrong passwords are limited per address (an anonymous
// daily code, see salt.php). Passwords are stored as bcrypt hashes.

declare(strict_types=1);

require_once __DIR__ . '/salt.php';

const LF_LOGIN_TRIES = 8;      // wrong passwords allowed per 15 minutes, per address
const LF_PASSWORD_MIN = 10;
const LF_PASSWORD_MAX = 72;    // bcrypt only looks at the first 72 bytes

function lf_password_problem(string $password, string $confirm): ?string
{
    if (mb_strlen($password) < LF_PASSWORD_MIN) {
        return 'Please use a password of ' . LF_PASSWORD_MIN . ' characters or more.';
    }
    if (strlen($password) > LF_PASSWORD_MAX) {
        return 'That password is too long. Please keep it under ' . LF_PASSWORD_MAX . ' characters.';
    }
    if ($password !== $confirm) {
        return 'The two passwords don’t match.';
    }
    return null;
}

function lf_token_hash(string $token): string
{
    return hash('sha256', $token, true);
}

// Logs a member in. Returns the token for their cookie. $viaHost marks a login that came through a host
// app: those end sooner (host.idle_minutes, host.max_hours in config.php).
function lf_session_start(PDO $pdo, int $memberId, bool $viaHost = false): string
{
    $token = bin2hex(random_bytes(32));
    $now = lf_now();
    $pdo->prepare("INSERT INTO sessions (token_hash, member_id, csrf, via_host, created_at, last_used_at) VALUES (:t, :m, :c, :v, :n1, :n2)")
        ->execute(['t' => lf_token_hash($token), 'm' => $memberId, 'c' => bin2hex(random_bytes(16)), 'v' => (int) $viaHost, 'n1' => $now, 'n2' => $now]);
    $pdo->prepare("UPDATE members SET last_seen_at = :n WHERE id = :m")->execute(['n' => $now, 'm' => $memberId]);
    return $token;
}

// The member a login cookie belongs to (with the login's own csrf and flash values), or null when the
// cookie is unknown, expired, or the member was removed.
function lf_session_lookup(PDO $pdo, string $token): ?array
{
    if (!preg_match('~^[0-9a-f]{64}$~D', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT m.id, m.account_id, m.username, m.role, m.status, m.host_ref, m.created_at, m.last_seen_at,
                s.csrf, s.flash, s.via_host, s.created_at AS session_created, s.last_used_at AS session_used, HEX(s.token_hash) AS session_key
         FROM sessions s JOIN members m ON m.id = s.member_id
         WHERE s.token_hash = :t AND m.status <> 'removed'"
    );
    $stmt->execute(['t' => lf_token_hash($token)]);
    $member = $stmt->fetch();
    if (!$member) {
        return null;
    }
    $viaHost = (int) $member['via_host'] === 1;
    $idle = time() - strtotime($member['session_used'] . ' UTC');
    $age = time() - strtotime($member['session_created'] . ' UTC');
    // A login through a host app follows the host's own limits: a short idle time and a longest total time.
    $idleLimit = $viaHost ? (int) lf_cfg('host.idle_minutes', 60) * 60 : (int) lf_cfg('session_days', 30) * 86400;
    if ($idle > $idleLimit || ($viaHost && $age > (int) lf_cfg('host.max_hours', 12) * 3600)) {
        lf_session_end($pdo, $token);
        return null;
    }
    if ($idle > ($viaHost ? 120 : 600)) {   // stays logged in while in use, without a write on every page
        $now = lf_now();
        $pdo->prepare("UPDATE sessions SET last_used_at = :n WHERE token_hash = :t")->execute(['n' => $now, 't' => lf_token_hash($token)]);
        $pdo->prepare("UPDATE members SET last_seen_at = :n WHERE id = :m")->execute(['n' => $now, 'm' => $member['id']]);
    }
    unset($member['session_used'], $member['session_created']);
    return $member;
}

function lf_session_end(PDO $pdo, string $token): void
{
    $pdo->prepare("DELETE FROM sessions WHERE token_hash = :t")->execute(['t' => lf_token_hash($token)]);
}

// Ends every login of a member, except (optionally) the one in $keepToken.
function lf_sessions_end_all(PDO $pdo, int $memberId, string $keepToken = ''): void
{
    $pdo->prepare("DELETE FROM sessions WHERE member_id = :m AND token_hash <> :k")
        ->execute(['m' => $memberId, 'k' => $keepToken !== '' ? lf_token_hash($keepToken) : '']);
}

// A real hash of a throwaway password, checked when a username doesn't exist so that a wrong username
// takes as long as a wrong password. Made with the same settings new passwords get.
function lf_dummy_hash(PDO $pdo): string
{
    $hash = lf_setting($pdo, 'dummy_hash');
    if ($hash === null) {
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        lf_setting_set($pdo, 'dummy_hash', $hash);
    }
    return $hash;
}

// Checks a username and password. Returns [message, null] when it fails or [null, token] on success.
function lf_login(PDO $pdo, string $username, string $password, string $ip): array
{
    $who = lf_addr_code(lf_salt($pdo), 'login', $ip);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_failures WHERE who = :w AND at >= :t");
    $stmt->execute(['w' => $who, 't' => gmdate('Y-m-d H:i:s', time() - 900)]);
    if ((int) $stmt->fetchColumn() >= LF_LOGIN_TRIES) {
        return ['Too many wrong passwords. Please wait 15 minutes and try again.', null];
    }
    $stmt = $pdo->prepare("SELECT id, password_hash, status, host_ref FROM members WHERE username_key = :k");
    $stmt->execute(['k' => lf_username_key(trim($username))]);
    $member = $stmt->fetch();
    $hash = $member['password_hash'] ?? null;
    // Always check a hash, so a wrong username takes as long as a wrong password.
    $ok = password_verify($password, $hash ?? lf_dummy_hash($pdo));
    // People who come in through a host app can't log in here at all, whatever password they type.
    if (!$member || !$ok || $hash === null || $member['status'] === 'removed' || $member['host_ref'] !== null) {
        $pdo->prepare("INSERT INTO login_failures (who, at) VALUES (:w, :t)")->execute(['w' => $who, 't' => lf_now()]);
        return ['That username and password don’t match.', null];
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $pdo->prepare("UPDATE members SET password_hash = :h WHERE id = :m")->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'm' => $member['id']]);
    }
    return [null, lf_session_start($pdo, (int) $member['id'])];
}

// Changes a password. Returns null when it worked, or a message. Every other login of the member ends.
function lf_password_change(PDO $pdo, int $memberId, string $current, string $new, string $confirm, string $keepToken): ?string
{
    $stmt = $pdo->prepare("SELECT password_hash FROM members WHERE id = :m AND status <> 'removed'");
    $stmt->execute(['m' => $memberId]);
    $hash = $stmt->fetchColumn();
    if (!is_string($hash) || !password_verify($current, $hash)) {
        return 'That isn’t your current password.';
    }
    if ($problem = lf_password_problem($new, $confirm)) {
        return $problem;
    }
    $pdo->prepare("UPDATE members SET password_hash = :h WHERE id = :m")->execute(['h' => password_hash($new, PASSWORD_DEFAULT), 'm' => $memberId]);
    lf_sessions_end_all($pdo, $memberId, $keepToken);
    return null;
}

// Sets a member's password without needing the old one (for a lost password: bin/member.php password).
// Every login of theirs ends.
function lf_password_set(PDO $pdo, int $memberId, string $password): void
{
    $pdo->prepare("UPDATE members SET password_hash = :h WHERE id = :m AND status <> 'removed'")->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'm' => $memberId]);
    lf_sessions_end_all($pdo, $memberId);
}

// Every form carries the login's own csrf value, so a page on another site can't post as the member.
function lf_csrf_ok(?array $me, array $post): bool
{
    return $me !== null && hash_equals((string) $me['csrf'], (string) ($post['csrf'] ?? ''));
}

// A short message to show once on the member's next page.
function lf_flash_set(PDO $pdo, array $me, string $kind, string $message): void
{
    $pdo->prepare("UPDATE sessions SET flash = :f WHERE token_hash = UNHEX(:k)")
        ->execute(['f' => json_encode([$kind, mb_substr($message, 0, 250)], JSON_UNESCAPED_UNICODE), 'k' => $me['session_key']]);
}

// The message left for this page (and forgets it), as [kind, text], or null.
function lf_flash_take(PDO $pdo, array $me): ?array
{
    if ($me['flash'] === null || $me['flash'] === '') {
        return null;
    }
    $pdo->prepare("UPDATE sessions SET flash = NULL WHERE token_hash = UNHEX(:k)")->execute(['k' => $me['session_key']]);
    $flash = json_decode((string) $me['flash'], true);
    return is_array($flash) && count($flash) === 2 ? [(string) $flash[0], (string) $flash[1]] : null;
}
