<?php
// Running inside another app. A host app (a control panel, say) that has already logged its own user in
// can send them to the forum with a signed link. The forum trusts the signature, so people who arrive this
// way have no password here at all and can't use the forum's own login page. Settings, in config.php:
//   'host' => ['secret' => '<long random text shared with the host app>',
//              'frame_ancestors' => ['https://host.example'],   // pages that may show the forum inside a frame
//              'idle_minutes' => 60, 'max_hours' => 12]         // how long such a login lasts
//
// The link:   https://forum.example/enter?t=<token>
// The token:  base64url(payload) "." base64url(HMAC-SHA256 of that payload text, with the shared secret)
// The payload is JSON: v (1), ref (the host's id for the person), acct (the host's id for their account),
// acct_name (optional: what to call the account), iat and exp (Unix times, at most 5 minutes apart),
// jti (32 random hex characters, used once) and next (optional path to open first, such as /t/12).
// Links should live about a minute. lf_host_token_make() below builds one, for hosts written in PHP.
//
// The host can also manage the team of one of its accounts (the people who log in on the forum's own page):
//   POST https://forum.example/host/api   with a JSON body such as {"op": "team.list", "acct": "42"}
// signed with three headers: X-Host-Time (Unix time, within 2 minutes), X-Host-Nonce (32 random hex characters,
// used once) and X-Host-Signature (hex HMAC-SHA256 of "time.nonce.body" with the shared secret). Operations,
// all for the account named in "acct":
//   team.list                                        the team, open invitations and places used
//   team.create  {"username": "maria", "password": "…"}  adds somebody, with the username and password the account holder chose
//   team.password  {"member": 5, "password": "…"}     a new password for somebody on the team (their logins end)
//   team.invite  {"note": "Maria", "days": 7}         a one-time invitation link instead (shown only now)
//   team.revoke  {"invite": 3}                        cancels an open invitation
//   team.remove  {"member": 5}                        takes somebody off the team
// Answers are JSON: {"ok": true, ...} or {"ok": false, "error": "..."}.

declare(strict_types=1);

require_once __DIR__ . '/members.php';
require_once __DIR__ . '/text.php';

const LF_HOST_MAX_LIFETIME = 300;
const LF_HOST_API_WINDOW = 120;
const LF_HOST_LINK_USED = 'This link was already used. Please open the forum again from your account.';

function lf_b64u(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function lf_b64u_decode(string $text): ?string
{
    $binary = base64_decode(strtr($text, '-_', '+/'), true);
    return $binary === false ? null : $binary;
}

// Makes a token. $claims needs ref and acct; iat, exp (a minute), jti and v are filled in.
function lf_host_token_make(array $claims, string $secret, ?int $now = null): string
{
    $now ??= time();
    $claims += ['v' => 1, 'iat' => $now, 'exp' => $now + 60, 'jti' => bin2hex(random_bytes(16))];
    $payload = lf_b64u((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $payload . '.' . lf_b64u(hash_hmac('sha256', $payload, $secret, true));
}

// Checks a token. Returns [payload, null], or [null, reason]. The reason is for the server's log, not the visitor.
function lf_host_token_verify(string $token, string $secret, ?int $now = null): array
{
    $now ??= time();
    if ($secret === '' || strlen($token) > 2000 || !preg_match('~^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$~D', $token, $parts)) {
        return [null, 'malformed'];
    }
    $signature = lf_b64u_decode($parts[2]);
    if ($signature === null || !hash_equals(hash_hmac('sha256', $parts[1], $secret, true), $signature)) {
        return [null, 'bad signature'];
    }
    $json = lf_b64u_decode($parts[1]);
    $claims = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($claims) || ($claims['v'] ?? null) !== 1) {
        return [null, 'unknown version'];
    }
    foreach (['ref', 'acct'] as $key) {
        if (!is_string($claims[$key] ?? null) || !preg_match(LF_HOST_ID, $claims[$key])) {
            return [null, "bad $key"];
        }
    }
    if (!is_string($claims['jti'] ?? null) || !preg_match('~^[0-9a-f]{32}$~D', $claims['jti'])) {
        return [null, 'bad jti'];
    }
    if (!is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)) {
        return [null, 'bad times'];
    }
    if ($claims['exp'] <= $now) {
        return [null, 'expired'];
    }
    if ($claims['iat'] > $now + 60 || $claims['exp'] - $claims['iat'] > LF_HOST_MAX_LIFETIME) {
        return [null, 'bad times'];
    }
    if (isset($claims['next']) && (!is_string($claims['next']) || !preg_match('~^/[A-Za-z0-9/_-]{0,100}$~D', $claims['next']))) {
        return [null, 'bad next'];
    }
    $claims['acct_name'] = mb_substr(trim(preg_replace('~\s+~u', ' ', lf_clean_text((string) ($claims['acct_name'] ?? ''))) ?? ''), 0, 100);
    return [$claims, null];
}

// Lets a person in with a verified token: uses the token up, finds their member (making one, in their
// account, the first time) and returns [null, member id], or [message, null]. A member the owner removed
// stays removed: the host can't bring them back.
function lf_host_enter(PDO $pdo, array $claims): array
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO host_tokens (jti, expires_at) VALUES (:j, :e)")
                ->execute(['j' => $claims['jti'], 'e' => gmdate('Y-m-d H:i:s', $claims['exp'] + 3600)]);
            $stmt = $pdo->prepare("SELECT id, status FROM members WHERE host_ref = :r FOR UPDATE");
            $stmt->execute(['r' => $claims['ref']]);
            $member = $stmt->fetch();
            if ($member) {
                if ($member['status'] === 'removed') {
                    $pdo->rollBack();
                    return ['This forum isn’t available for your account.', null];
                }
                $memberId = (int) $member['id'];
            } else {
                $accountId = lf_account_for_host($pdo, $claims['acct'], $claims['acct_name']);
                if (lf_account_full($pdo, $accountId)) {
                    $pdo->rollBack();
                    return ['This account has no free places left.', null];
                }
                $memberId = lf_member_create($pdo, $accountId, null, null, 'member', $claims['ref']);
            }
            $pdo->commit();
            return [null, $memberId];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!lf_is_duplicate($e)) {
                throw $e;
            }
            // Either this link was already used, or two first visits raced to make the same member.
            $stmt = $pdo->prepare("SELECT 1 FROM host_tokens WHERE jti = :j");
            $stmt->execute(['j' => $claims['jti']]);
            if ($stmt->fetchColumn()) {
                return [LF_HOST_LINK_USED, null];
            }
        }
    }
    return ['Something went wrong. Please try again.', null];
}

// Checks a signed request from the host app (see the top of this file). Each request can be used once.
function lf_host_api_verify(PDO $pdo, string $secret, string $body, array $server, ?int $now = null): bool
{
    $now ??= time();
    $time = (string) ($server['HTTP_X_HOST_TIME'] ?? '');
    $nonce = (string) ($server['HTTP_X_HOST_NONCE'] ?? '');
    $signature = (string) ($server['HTTP_X_HOST_SIGNATURE'] ?? '');
    if ($secret === '' || !ctype_digit($time) || abs($now - (int) $time) > LF_HOST_API_WINDOW
        || !preg_match('~^[0-9a-f]{32}$~D', $nonce) || !preg_match('~^[0-9a-f]{64}$~D', $signature)) {
        return false;
    }
    if (!hash_equals(hash_hmac('sha256', "$time.$nonce.$body", $secret), $signature)) {
        return false;
    }
    try {
        $pdo->prepare("INSERT INTO host_tokens (jti, expires_at) VALUES (:j, :e)")->execute(['j' => $nonce, 'e' => gmdate('Y-m-d H:i:s', $now + 3600)]);
    } catch (PDOException $e) {
        if (lf_is_duplicate($e)) {
            return false;   // the same request sent twice
        }
        throw $e;
    }
    return true;
}

// Does what a verified host request asks for. Returns [HTTP status, answer].
function lf_host_api(PDO $pdo, array $input): array
{
    $acct = $input['acct'] ?? null;
    if (!is_string($acct) || !preg_match(LF_HOST_ID, $acct)) {
        return [400, ['ok' => false, 'error' => 'A valid "acct" is needed.']];
    }
    $op = (string) ($input['op'] ?? '');
    $accountId = lf_account_id_for_host($pdo, $acct);
    $date = fn (?string $utc) => $utc === null ? null : lf_iso($utc);
    switch ($op) {
        case 'team.list':
            $team = $accountId === null ? ['members' => [], 'invites' => [], 'seats' => ['used' => 0, 'limit' => lf_host_default_limit()]] : lf_team_list($pdo, $accountId);
            return [200, ['ok' => true,
                'members' => array_map(fn ($m) => ['id' => (int) $m['id'], 'username' => $m['username'], 'muted' => $m['status'] === 'muted',
                    'joined' => $date($m['created_at']), 'last_seen' => $date($m['last_seen_at'])], $team['members']),
                'invites' => array_map(fn ($i) => ['id' => (int) $i['id'], 'note' => $i['note'], 'created' => $date($i['created_at']), 'expires' => $date($i['expires_at'])], $team['invites']),
                'seats' => $team['seats']]];
        case 'team.invite':
            $accountId ??= lf_account_for_host($pdo, $acct, '');
            [$problem, $token, $expires] = lf_team_invite($pdo, $accountId, (string) ($input['note'] ?? ''), (int) ($input['days'] ?? 7));
            return $problem !== null ? [409, ['ok' => false, 'error' => $problem]]
                : [200, ['ok' => true, 'url' => lf_abs_url('invite/' . $token), 'expires' => $date($expires)]];
        case 'team.create':
            $accountId ??= lf_account_for_host($pdo, $acct, '');
            [$problem, $memberId] = lf_team_create($pdo, $accountId, (string) ($input['username'] ?? ''), (string) ($input['password'] ?? ''));
            return $problem !== null ? [409, ['ok' => false, 'error' => $problem]] : [200, ['ok' => true, 'id' => $memberId]];
        case 'team.password':
            $problem = $accountId === null ? 'That person isn’t on your team.' : lf_team_password($pdo, $accountId, (int) ($input['member'] ?? 0), (string) ($input['password'] ?? ''));
            return $problem !== null ? [409, ['ok' => false, 'error' => $problem]] : [200, ['ok' => true]];
        case 'team.revoke':
            if ($accountId !== null) {
                lf_team_revoke($pdo, $accountId, (int) ($input['invite'] ?? 0));
            }
            return [200, ['ok' => true]];
        case 'team.remove':
            $problem = $accountId === null ? 'That person isn’t on your team.' : lf_team_remove($pdo, $accountId, (int) ($input['member'] ?? 0));
            return $problem !== null ? [409, ['ok' => false, 'error' => $problem]] : [200, ['ok' => true]];
    }
    return [400, ['ok' => false, 'error' => 'Unknown operation.']];
}
