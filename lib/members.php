<?php
// People: accounts, members, usernames and invitations. An account is a group of people who belong
// together (a business, a team). A member belongs to an account, and everything a member writes belongs
// to them. A stand-alone forum has a single account.

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/perm.php';

// What a host app's id for a person or an account may look like.
const LF_HOST_ID = '~^[A-Za-z0-9._:@-]{1,100}$~D';

// The same name in a form that ignores capitals and underscores, so nobody can register "Ranzy_" next to "ranzy".
function lf_username_key(string $name): string
{
    return strtolower(str_replace('_', '', $name));
}

function lf_username_reserved(PDO $pdo, string $name): bool
{
    $reserved = array_map(fn ($n) => lf_username_key((string) $n), (array) lf_cfg('reserved_names', []));
    $reserved[] = lf_username_key(preg_replace('~[^A-Za-z0-9_]~', '', lf_forum_name($pdo)) ?? '');
    return in_array(lf_username_key($name), $reserved, true);
}

// A message when a username can't be used, or null. The owner may take a reserved name when setting up.
function lf_username_problem(PDO $pdo, string $name, bool $allowReserved = false): ?string
{
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9_]{2,19}$~D', $name)) {
        return 'Use 3 to 20 letters, digits or underscores, starting with a letter or digit.';
    }
    if (!$allowReserved && lf_username_reserved($pdo, $name)) {
        return 'That name is reserved. Please choose another.';
    }
    $stmt = $pdo->prepare("SELECT 1 FROM members WHERE username_key = :k");
    $stmt->execute(['k' => lf_username_key($name)]);
    return $stmt->fetchColumn() ? 'That name is already taken.' : null;
}

function lf_is_duplicate(PDOException $e): bool
{
    return (int) ($e->errorInfo[1] ?? 0) === 1062;
}

// ---------- accounts ----------

function lf_account_create(PDO $pdo, string $name, ?int $memberLimit = null): int
{
    $pdo->prepare("INSERT INTO accounts (name, member_limit, created_at) VALUES (:n, :l, :t)")
        ->execute(['n' => mb_substr($name, 0, 100), 'l' => $memberLimit, 't' => lf_now()]);
    return (int) $pdo->lastInsertId();
}

// The forum account for a host app's account id (made the first time), so the people of one account belong together.
function lf_account_for_host(PDO $pdo, string $hostAccount, string $name): int
{
    $find = $pdo->prepare("SELECT id FROM accounts WHERE host_ref = :h");
    $find->execute(['h' => $hostAccount]);
    if (($id = $find->fetchColumn()) !== false) {
        return (int) $id;
    }
    try {
        $pdo->prepare("INSERT INTO accounts (name, host_ref, created_at) VALUES (:n, :h, :t)")
            ->execute(['n' => $name !== '' ? mb_substr($name, 0, 100) : 'Account ' . $hostAccount, 'h' => $hostAccount, 't' => lf_now()]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (!lf_is_duplicate($e)) {
            throw $e;
        }
        $find->execute(['h' => $hostAccount]);   // someone else made it a moment ago
        return (int) $find->fetchColumn();
    }
}

// How many people an account has and how many it may have (null: no limit).
function lf_account_seats(PDO $pdo, int $accountId): array
{
    $stmt = $pdo->prepare("SELECT member_limit, (SELECT COUNT(*) FROM members WHERE account_id = :a1 AND status <> 'removed') AS used FROM accounts WHERE id = :a2");
    $stmt->execute(['a1' => $accountId, 'a2' => $accountId]);
    $row = $stmt->fetch();
    return ['used' => (int) ($row['used'] ?? 0), 'limit' => isset($row['member_limit']) ? (int) $row['member_limit'] : null];
}

function lf_account_full(PDO $pdo, int $accountId): bool
{
    $seats = lf_account_seats($pdo, $accountId);
    return $seats['limit'] !== null && $seats['used'] >= $seats['limit'];
}

// ---------- members ----------

function lf_owner_exists(PDO $pdo): bool
{
    return (bool) $pdo->query("SELECT 1 FROM members WHERE role = 'owner' AND status <> 'removed' LIMIT 1")->fetchColumn();
}

function lf_member_create(PDO $pdo, int $accountId, ?string $username, ?string $passwordHash, string $role = 'member', ?string $hostRef = null): int
{
    $pdo->prepare("INSERT INTO members (account_id, username, username_key, password_hash, host_ref, role, created_at) VALUES (:a, :u, :k, :p, :h, :r, :t)")
        ->execute(['a' => $accountId, 'u' => $username, 'k' => $username === null ? null : lf_username_key($username),
            'p' => $passwordHash, 'h' => $hostRef, 'r' => $role, 't' => lf_now()]);
    return (int) $pdo->lastInsertId();
}

// One member, without the password hash.
function lf_member_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT id, account_id, username, role, status, host_ref, created_at, last_seen_at FROM members WHERE id = :m");
    $stmt->execute(['m' => $id]);
    return $stmt->fetch() ?: null;
}

// Everyone who hasn't left: owner first, then moderators, then members by name.
function lf_members_list(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, account_id, username, role, status, created_at, last_seen_at FROM members WHERE status <> 'removed'
         ORDER BY FIELD(role, 'owner', 'moderator', 'member'), username_key IS NULL, username_key"
    )->fetchAll();
}

// A member who has no username yet chooses one. Returns a message when it can't be used.
function lf_member_set_username(PDO $pdo, int $memberId, string $username): ?string
{
    $username = trim($username);
    if ($problem = lf_username_problem($pdo, $username)) {
        return $problem;
    }
    try {
        $stmt = $pdo->prepare("UPDATE members SET username = :u, username_key = :k WHERE id = :m AND username IS NULL AND status <> 'removed'");
        $stmt->execute(['u' => $username, 'k' => lf_username_key($username), 'm' => $memberId]);
    } catch (PDOException $e) {
        if (lf_is_duplicate($e)) {
            return 'That name is already taken.';
        }
        throw $e;
    }
    return $stmt->rowCount() === 1 ? null : 'You already have a username.';
}

// Looks up somebody a staff member wants to act on. Returns [member, null] or [null, message].
function lf_member_target(PDO $pdo, array $me, int $targetId): array
{
    $target = lf_member_get($pdo, $targetId);
    if ($target === null || $target['status'] === 'removed') {
        return [null, 'That member doesn’t exist.'];
    }
    if ((int) $target['id'] === (int) $me['id']) {
        return [null, 'You can’t do that to yourself.'];
    }
    if ($target['role'] === 'owner') {
        return [null, 'The owner can’t be changed.'];
    }
    return [$target, null];
}

// Staff can mute a member (they can still read, but not write) and lift it again.
function lf_member_mute(PDO $pdo, array $me, int $targetId, bool $mute): ?string
{
    if (!lf_is_staff($me)) {
        return 'Only moderators can do that.';
    }
    [$target, $problem] = lf_member_target($pdo, $me, $targetId);
    if ($problem !== null) {
        return $problem;
    }
    if ($target['role'] !== 'member') {
        return 'Moderators can’t be muted. Make them a member first.';
    }
    $pdo->prepare("UPDATE members SET status = :s WHERE id = :m AND status <> 'removed'")->execute(['s' => $mute ? 'muted' : 'active', 'm' => $targetId]);
    return null;
}

// The owner makes somebody a moderator, or a plain member again.
function lf_member_set_role(PDO $pdo, array $me, int $targetId, string $role): ?string
{
    if (!lf_is_owner($me)) {
        return 'Only the owner can do that.';
    }
    if (!in_array($role, ['member', 'moderator'], true)) {
        return 'Unknown role.';
    }
    [, $problem] = lf_member_target($pdo, $me, $targetId);
    if ($problem !== null) {
        return $problem;
    }
    // A moderator can't be muted, so making someone one also lifts a mute.
    $pdo->prepare("UPDATE members SET role = :r, status = IF(:r2 = 'moderator', 'active', status) WHERE id = :m")->execute(['r' => $role, 'r2' => $role, 'm' => $targetId]);
    return null;
}

// The owner removes a member. What they wrote stays, shown as "Former member". Their username, password
// and logins are wiped, and the username can be taken again.
function lf_member_remove(PDO $pdo, array $me, int $targetId): ?string
{
    if (!lf_is_owner($me)) {
        return 'Only the owner can do that.';
    }
    [, $problem] = lf_member_target($pdo, $me, $targetId);
    if ($problem !== null) {
        return $problem;
    }
    lf_member_wipe($pdo, $targetId);
    return null;
}

// Turns a member into a "Former member": no name, no password, no logins, no reading history. Their host
// id stays (that is what stops the host app from bringing them straight back in).
function lf_member_wipe(PDO $pdo, int $memberId): void
{
    $pdo->prepare("UPDATE members SET status = 'removed', role = 'member', username = NULL, username_key = NULL, password_hash = NULL WHERE id = :m")->execute(['m' => $memberId]);
    $pdo->prepare("DELETE FROM sessions WHERE member_id = :m")->execute(['m' => $memberId]);
    $pdo->prepare("DELETE FROM thread_reads WHERE member_id = :m")->execute(['m' => $memberId]);
}

// Makes a member come in only through the app that runs the forum: their password is erased, their host
// id is set and their logins end. For an account that was made with a password before the host took over.
// Returns a message when it can't be done.
function lf_member_make_host_only(PDO $pdo, int $memberId, string $hostRef): ?string
{
    if (!preg_match(LF_HOST_ID, $hostRef)) {
        return 'That host id isn’t valid.';
    }
    $member = lf_member_get($pdo, $memberId);
    if ($member === null || $member['status'] === 'removed') {
        return 'That member doesn’t exist.';
    }
    try {
        $pdo->prepare("UPDATE members SET password_hash = NULL, host_ref = :h WHERE id = :m")->execute(['h' => $hostRef, 'm' => $memberId]);
    } catch (PDOException $e) {
        if (lf_is_duplicate($e)) {
            return 'Somebody else already has that host id.';
        }
        throw $e;
    }
    lf_sessions_end_all($pdo, $memberId);
    return null;
}

// What to show as a name: the username, or "Former member" for someone who left.
function lf_display_name(?string $username, ?string $status = null): string
{
    return $username !== null && $username !== '' && $status !== 'removed' ? $username : 'Former member';
}

// ---------- invitations ----------

// The owner makes a one-time link. Only its hash is kept, so the link is shown once. Returns [message, null] or [null, token].
function lf_invite_create(PDO $pdo, array $me, string $role = 'member', int $days = 7): array
{
    if (!lf_is_owner($me)) {
        return ['Only the owner can invite people.', null];
    }
    if (!in_array($role, ['member', 'moderator'], true)) {
        return ['Unknown role.', null];
    }
    if (lf_account_full($pdo, (int) $me['account_id'])) {
        return ['This account has no free places left.', null];
    }
    $token = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO invites (account_id, token_hash, role, created_by, created_at, expires_at) VALUES (:a, :h, :r, :c, :n, :e)")
        ->execute(['a' => $me['account_id'], 'h' => lf_token_hash($token), 'r' => $role, 'c' => $me['id'], 'n' => lf_now(),
            'e' => gmdate('Y-m-d H:i:s', time() + max(1, min(90, $days)) * 86400)]);
    return [null, $token];
}

// The invitation behind a link, if it is still good (not used, not expired).
function lf_invite_find(PDO $pdo, string $token): ?array
{
    if (!preg_match('~^[0-9a-f]{32}$~D', $token)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, account_id, role, expires_at FROM invites WHERE token_hash = :h AND used_at IS NULL AND expires_at > :n");
    $stmt->execute(['h' => lf_token_hash($token), 'n' => lf_now()]);
    return $stmt->fetch() ?: null;
}

function lf_invites_open(PDO $pdo, int $accountId): array
{
    $stmt = $pdo->prepare("SELECT id, role, created_at, expires_at FROM invites WHERE account_id = :a AND used_at IS NULL AND expires_at > :n ORDER BY id DESC");
    $stmt->execute(['a' => $accountId, 'n' => lf_now()]);
    return $stmt->fetchAll();
}

function lf_invite_revoke(PDO $pdo, array $me, int $inviteId): ?string
{
    if (!lf_is_owner($me)) {
        return 'Only the owner can do that.';
    }
    $pdo->prepare("DELETE FROM invites WHERE id = :i AND account_id = :a AND used_at IS NULL")->execute(['i' => $inviteId, 'a' => $me['account_id']]);
    return null;
}

// Somebody follows an invitation and picks a username and password. Returns [message, null] or [null, member id].
function lf_invite_accept(PDO $pdo, string $token, string $username, string $password, string $confirm): array
{
    $gone = 'This invitation link has expired or was already used. Please ask for a new one.';
    if (lf_invite_find($pdo, $token) === null) {
        return [$gone, null];
    }
    $username = trim($username);
    $problem = lf_username_problem($pdo, $username) ?? lf_password_problem($password, $confirm);
    if ($problem !== null) {
        return [$problem, null];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        // Lock the invitation, so two people can't use one link at the same moment.
        $stmt = $pdo->prepare("SELECT id, account_id, role FROM invites WHERE token_hash = :h AND used_at IS NULL AND expires_at > :n FOR UPDATE");
        $stmt->execute(['h' => lf_token_hash($token), 'n' => lf_now()]);
        $invite = $stmt->fetch();
        if (!$invite) {
            $pdo->rollBack();
            return [$gone, null];
        }
        $pdo->prepare("SELECT id FROM accounts WHERE id = :a FOR UPDATE")->execute(['a' => $invite['account_id']]);
        if (lf_account_full($pdo, (int) $invite['account_id'])) {
            $pdo->rollBack();
            return ['This account has no free places left.', null];
        }
        $memberId = lf_member_create($pdo, (int) $invite['account_id'], $username, $hash, $invite['role']);
        $pdo->prepare("UPDATE invites SET used_at = :n, used_by = :m WHERE id = :i")->execute(['n' => lf_now(), 'm' => $memberId, 'i' => $invite['id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (lf_is_duplicate($e)) {
            return ['That name is already taken.', null];
        }
        throw $e;
    }
    return [null, $memberId];
}
