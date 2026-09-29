<?php
// LibreForum checks. They use the database named as unit_db in tests/config.php, wiped on every run.
//   php tests/run.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
foreach (['http', 'theme', 'text', 'salt', 'auth', 'members', 'perm', 'setup', 'forum', 'maintain', 'pages'] as $lib) {
    require_once __DIR__ . "/../lib/$lib.php";
}

if (!is_file(__DIR__ . '/config.php')) {
    exit("tests/config.php is missing. Copy tests/config.example.php and fill it in.\n");
}
$t = require __DIR__ . '/config.php';
if (!preg_match('~(_test|_e2e)$~', (string) $t['unit_db'])) {
    exit("Refusing to wipe {$t['unit_db']}: a test database's name must end in _test or _e2e.\n");
}
$base = [
    'db' => ['host' => $t['host'], 'name' => $t['unit_db'], 'user' => $t['user'], 'pass' => $t['pass']],
    'name' => 'Test Forum', 'reserved_names' => ['admin', 'staff'],
    'limits' => ['posts_per_hour' => 1000, 'threads_per_day' => 1000, 'seconds_between_posts' => 0],
    'session_days' => 30, 'trusted_proxies' => ['10.0.0.1'], 'theme' => 'default', 'theme_paths' => [],
];
lf_config($base);
$pdo = lf_connect($base['db']);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec("DROP TABLE `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$failed = 0;
$passed = 0;
function check(string $what, bool $ok): void
{
    global $failed, $passed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok    ' : '  FAIL  '), $what, "\n";
}
function one(PDO $pdo, string $sql): mixed
{
    return $pdo->query($sql)->fetchColumn();
}
function ago(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() - $seconds);
}
// A member for a test (cheap password hash), joined a day ago. Returns what the pages pass around as $me.
function mk(PDO $pdo, string $name, string $role = 'member', int $account = 1): array
{
    $id = lf_member_create($pdo, $account, $name, password_hash('password-for-tests', PASSWORD_BCRYPT, ['cost' => 4]), $role);
    $pdo->prepare("UPDATE members SET created_at = :t WHERE id = :m")->execute(['t' => ago(86400), 'm' => $id]);
    return me($pdo, $id);
}
function me(PDO $pdo, int $id): array
{
    return lf_member_get($pdo, $id) + ['csrf' => 'csrf-value', 'flash' => null, 'session_key' => ''];
}
function cfg(array $override): void
{
    global $base;
    lf_config(array_replace_recursive($base, $override));
}
function tid(array $result): int
{
    return (int) $result[1];
}

echo "Installing\n";
lf_install_schema($pdo);
lf_install_schema($pdo);
check('the tables are created, and creating them again is harmless', (int) one($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()") === 12);
check('the schema file has no semicolons in its comments', count(lf_schema_statements()) === 12);

echo "Daily secrets\n";
$salt = lf_salt($pdo);
check('the daily secret is 32 random bytes', strlen($salt) === 32);
check('it stays the same all day', lf_salt($pdo) === $salt && (int) one($pdo, "SELECT COUNT(*) FROM salts") === 1);
check('an address becomes an 8-byte code', strlen(lf_addr_code($salt, 'login', '203.0.113.5')) === 8);
check('the same address gets the same code', lf_addr_code($salt, 'login', '203.0.113.5') === lf_addr_code($salt, 'login', '203.0.113.5'));
check('another address, purpose or day gets another code', lf_addr_code($salt, 'login', '203.0.113.6') !== lf_addr_code($salt, 'login', '203.0.113.5')
    && lf_addr_code($salt, 'other', '203.0.113.5') !== lf_addr_code($salt, 'login', '203.0.113.5')
    && lf_addr_code(str_repeat('x', 32), 'login', '203.0.113.5') !== lf_addr_code($salt, 'login', '203.0.113.5'));
$pdo->prepare("INSERT INTO salts (day, salt) VALUES ('2020-01-01', :s)")->execute(['s' => random_bytes(32)]);
check('yesterday’s and older secrets are destroyed', lf_salt_prune($pdo) === 1 && (int) one($pdo, "SELECT COUNT(*) FROM salts") === 1);
check('the address behind a trusted proxy is read from X-Forwarded-For', lf_client_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.1'], ['10.0.0.1']) === '198.51.100.9');
check('X-Forwarded-For from anyone else is ignored', lf_client_ip(['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'], ['10.0.0.1']) === '192.0.2.1');

echo "Text\n";
check('control and direction-changing characters are stripped', lf_clean_text("a\r\nb\x00c\u{202E}d") === "a\nbcd");
check('a title is one tidy line', lf_clean_title("  Hello   \n world ") === 'Hello world');
check('titles need 3 to 150 characters', lf_title_problem('ab') !== null && lf_title_problem('abc') === null && lf_title_problem(str_repeat('x', 150)) === null && lf_title_problem(str_repeat('x', 151)) !== null);
check('posts need 1 to 10,000 characters', lf_body_problem('') !== null && lf_body_problem('x') === null && lf_body_problem(str_repeat('x', 10000)) === null && lf_body_problem(str_repeat('x', 10001)) !== null);
check('HTML in a post is escaped', lf_body_html('<script>alert(1)</script>') === '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
check('web links become safe links, and the sentence’s full stop stays outside',
    lf_body_html('See https://example.com/a?b=1&c=2.') === '<p>See <a href="https://example.com/a?b=1&amp;c=2" rel="noopener noreferrer nofollow ugc">https://example.com/a?b=1&amp;c=2</a>.</p>');
check('a closing bracket after a link is left out, one inside it is kept',
    str_contains(lf_body_html('(see https://example.com/x)'), '>https://example.com/x</a>)')
    && str_contains(lf_body_html('https://en.wikipedia.org/wiki/Foo_(bar)'), '>https://en.wikipedia.org/wiki/Foo_(bar)</a>'));
check('only http and https are linked', !str_contains(lf_body_html('javascript:alert(1) ftp://x.org http://'), '<a'));
check('a quote cannot break out of a link', lf_body_html('https://x.com/"onmouseover="alert(1)') === '<p><a href="https://x.com/" rel="noopener noreferrer nofollow ugc">https://x.com/</a>&quot;onmouseover=&quot;alert(1)</p>');
check('paragraphs and line breaks', lf_body_html("one\ntwo\n\nthree") === "<p>one<br>\ntwo</p>\n<p>three</p>");
check('`inline code` is shown as code, escaped, and links inside it are not made', lf_body_html('run `ls <b> -la` now') === '<p>run <code>ls &lt;b&gt; -la</code> now</p>' && !str_contains(lf_body_html('`https://a.com`'), '<a'));
check('fenced code blocks keep their text as it is', lf_body_html("before\n```php\n<?php echo 1;\n\n  indented\n```\nafter") === "<p>before</p>\n<pre><code>&lt;?php echo 1;\n\n  indented</code></pre>\n<p>after</p>");
check('an unfinished fence is just text', !str_contains(lf_body_html("```\nno end"), '<pre>'));
check('an excerpt is one short line', lf_excerpt("a\n\nb   c") === 'a b c' && lf_excerpt(str_repeat('word ', 100), 20) === 'word word word word…');

echo "Usernames\n";
$reserved = 'That name is reserved. Please choose another.';
check('a good name passes', lf_username_problem($pdo, 'ranzy') === null && lf_username_problem($pdo, 'a_b') === null);
check('too short, too long, odd characters and a leading underscore fail', lf_username_problem($pdo, 'ab') !== null && lf_username_problem($pdo, str_repeat('a', 21)) !== null
    && lf_username_problem($pdo, 'a-b') !== null && lf_username_problem($pdo, 'rönzy') !== null && lf_username_problem($pdo, '_abc') !== null);
check('a newline at the end is not sneaked in', lf_username_problem($pdo, "ranzy\n") !== null);
check('reserved names are refused, however they are written', lf_username_problem($pdo, 'admin') === $reserved && lf_username_problem($pdo, 'ADMIN') === $reserved
    && lf_username_problem($pdo, 'ad_min') === $reserved && lf_username_problem($pdo, 'Test_Forum') === $reserved);
check('the owner can take a reserved name when setting up', lf_username_problem($pdo, 'admin', true) === null);

echo "Setting up\n";
check('the forum needs a name', lf_setup_owner($pdo, '  ', 'boss', 'correct horse battery', 'correct horse battery')[0] !== null);
check('the owner needs a good password', lf_setup_owner($pdo, 'Test Forum', 'boss', 'short', 'short')[0] !== null
    && lf_setup_owner($pdo, 'Test Forum', 'boss', 'correct horse battery', 'correct horse batterx')[0] === 'The two passwords don’t match.');
check('nothing is created by a failed setup', !lf_owner_exists($pdo) && (int) one($pdo, "SELECT COUNT(*) FROM accounts") === 0);
[$problem, $ownerId] = lf_setup_owner($pdo, 'Test Forum', 'Boss', 'correct horse battery', 'correct horse battery');
check('setup creates the owner', $problem === null && lf_owner_exists($pdo) && lf_member_get($pdo, $ownerId)['role'] === 'owner');
check('and the forum’s account, name and first categories', (int) one($pdo, "SELECT COUNT(*) FROM accounts") === 1 && lf_forum_name($pdo) === 'Test Forum'
    && (int) one($pdo, "SELECT COUNT(*) FROM categories") === 3 && (int) one($pdo, "SELECT staff_only FROM categories WHERE slug = 'announcements'") === 1);
check('the password is stored as a hash', str_starts_with((string) one($pdo, "SELECT password_hash FROM members WHERE id = $ownerId"), '$2y$'));
check('setup works only once', lf_setup_owner($pdo, 'Other', 'Boss2', 'correct horse battery', 'correct horse battery')[0] === 'This forum has already been set up.');
$boss = me($pdo, $ownerId);
$boss['created_at'] = ago(86400);
$pdo->prepare("UPDATE members SET created_at = :t WHERE id = :m")->execute(['t' => $boss['created_at'], 'm' => $ownerId]);

echo "Logging in\n";
[$err, $token] = lf_login($pdo, 'boss', 'correct horse battery', '203.0.113.5');
check('the right username (any capitals) and password log in', $err === null && preg_match('~^[0-9a-f]{64}$~', (string) $token) === 1);
$me = lf_session_lookup($pdo, (string) $token);
check('the login cookie leads back to the member, without the password hash', $me !== null && $me['username'] === 'Boss' && $me['role'] === 'owner' && !array_key_exists('password_hash', $me));
check('only a hash of the cookie is stored', (int) one($pdo, "SELECT COUNT(*) FROM sessions WHERE HEX(token_hash) = '$token'") === 0 && (int) one($pdo, "SELECT COUNT(*) FROM sessions") === 1);
check('a made-up cookie leads nowhere', lf_session_lookup($pdo, str_repeat('a', 64)) === null && lf_session_lookup($pdo, 'nonsense') === null && lf_session_lookup($pdo, '') === null);
[$err] = lf_login($pdo, 'boss', 'wrong password!!', '203.0.113.5');
[$err2] = lf_login($pdo, 'nobody', 'wrong password!!', '203.0.113.5');
check('a wrong password and a wrong username get the same message', $err === 'That username and password don’t match.' && $err2 === $err);
check('a fake login check uses a stored dummy hash', str_starts_with((string) lf_setting($pdo, 'dummy_hash'), '$2y$'));
for ($i = 0; $i < 6; $i++) {
    lf_login($pdo, 'boss', 'wrong password!!', '203.0.113.5');
}
check('eight wrong passwords lock that address for a while, even for the right one', lf_login($pdo, 'boss', 'correct horse battery', '203.0.113.5')[0] === 'Too many wrong passwords. Please wait 15 minutes and try again.');
check('another address is not affected', lf_login($pdo, 'boss', 'correct horse battery', '203.0.113.99')[0] === null);
check('failures are stored as anonymous 8-byte codes', (int) one($pdo, "SELECT COUNT(*) FROM login_failures") === 8 && (int) one($pdo, "SELECT COUNT(*) FROM login_failures WHERE LENGTH(who) = 8") === 8);
$pdo->exec("DELETE FROM login_failures");
check('the lock ends when the failures are older than 15 minutes', lf_login($pdo, 'boss', 'correct horse battery', '203.0.113.5')[0] === null);

$pdo->prepare("UPDATE sessions SET last_used_at = :t WHERE HEX(token_hash) = :k")->execute(['t' => ago(700), 'k' => $me['session_key']]);
lf_session_lookup($pdo, (string) $token);
check('a login in use is kept fresh', time() - strtotime(one($pdo, "SELECT last_used_at FROM sessions WHERE HEX(token_hash) = '{$me['session_key']}'") . ' UTC') < 30);
$pdo->prepare("UPDATE sessions SET last_used_at = :t WHERE HEX(token_hash) = :k")->execute(['t' => ago(31 * 86400), 'k' => $me['session_key']]);
check('a login unused for 30 days ends', lf_session_lookup($pdo, (string) $token) === null && (int) one($pdo, "SELECT COUNT(*) FROM sessions WHERE HEX(token_hash) = '{$me['session_key']}'") === 0);
[, $token] = lf_login($pdo, 'boss', 'correct horse battery', '203.0.113.5');
lf_session_end($pdo, $token);
check('logging out ends the login', lf_session_lookup($pdo, $token) === null);

echo "Forms and messages\n";
[, $token] = lf_login($pdo, 'boss', 'correct horse battery', '203.0.113.5');
$me = lf_session_lookup($pdo, $token);
check('a form with the login’s csrf value is accepted', lf_csrf_ok($me, ['csrf' => $me['csrf']]));
check('a wrong, missing or logged-out csrf value is not', !lf_csrf_ok($me, ['csrf' => 'nope']) && !lf_csrf_ok($me, []) && !lf_csrf_ok(null, ['csrf' => $me['csrf']]));
lf_flash_set($pdo, $me, 'ok', 'Saved “it”.');
$me = lf_session_lookup($pdo, $token);
check('a message is shown once', lf_flash_take($pdo, $me) === ['ok', 'Saved “it”.'] && lf_flash_take($pdo, lf_session_lookup($pdo, $token)) === null);
check('forms must come from this site', lf_origin_ok(['HTTP_HOST' => 'forum.test', 'HTTP_ORIGIN' => 'https://forum.test'])
    && lf_origin_ok(['HTTP_HOST' => 'forum.test:8080', 'HTTP_ORIGIN' => 'http://forum.test:8080'])
    && !lf_origin_ok(['HTTP_HOST' => 'forum.test', 'HTTP_ORIGIN' => 'https://evil.test'])
    && !lf_origin_ok(['HTTP_HOST' => 'forum.test', 'HTTP_ORIGIN' => 'null'])
    && !lf_origin_ok(['HTTP_HOST' => 'forum.test', 'HTTP_ORIGIN' => 'https://forum.test.evil.test'])
    && lf_origin_ok(['HTTP_HOST' => 'forum.test', 'HTTP_SEC_FETCH_SITE' => 'same-origin'])
    && !lf_origin_ok(['HTTP_HOST' => 'forum.test', 'HTTP_SEC_FETCH_SITE' => 'cross-site'])
    && lf_origin_ok(['HTTP_HOST' => 'forum.test']));

echo "Passwords\n";
check('passwords need 10 to 72 characters that match', lf_password_problem('short', 'short') !== null && lf_password_problem(str_repeat('x', 73), str_repeat('x', 73)) !== null
    && lf_password_problem('long enough pw', 'long enough px') === 'The two passwords don’t match.' && lf_password_problem('long enough pw', 'long enough pw') === null);
$alice = mk($pdo, 'Alice');
[, $aliceToken] = lf_login($pdo, 'alice', 'password-for-tests', '203.0.113.7');
[, $aliceToken2] = lf_login($pdo, 'alice', 'password-for-tests', '203.0.113.8');
check('changing a password needs the current one', lf_password_change($pdo, (int) $alice['id'], 'not it not it', 'a brand new password', 'a brand new password', $aliceToken) === 'That isn’t your current password.');
check('and a good new one', lf_password_change($pdo, (int) $alice['id'], 'password-for-tests', 'short', 'short', $aliceToken) !== null
    && lf_password_change($pdo, (int) $alice['id'], 'password-for-tests', 'a brand new password', 'another brand new one', $aliceToken) === 'The two passwords don’t match.');
check('it works, ends her other logins and keeps this one', lf_password_change($pdo, (int) $alice['id'], 'password-for-tests', 'a brand new password', 'a brand new password', $aliceToken) === null
    && lf_session_lookup($pdo, $aliceToken) !== null && lf_session_lookup($pdo, $aliceToken2) === null);
check('the new password logs in, the old one does not', lf_login($pdo, 'alice', 'a brand new password', '203.0.113.7')[0] === null && lf_login($pdo, 'alice', 'password-for-tests', '203.0.113.7')[0] !== null);
$pdo->exec("DELETE FROM login_failures");

echo "Invitations\n";
$alice = mk($pdo, 'Alice2');
check('only the owner can invite', lf_invite_create($pdo, $alice)[0] === 'Only the owner can invite people.' && lf_invite_create($pdo, $boss, 'owner')[0] === 'Unknown role.');
[$problem, $invite] = lf_invite_create($pdo, $boss);
check('the owner gets a link that is a 32-character secret', $problem === null && preg_match('~^[0-9a-f]{32}$~', $invite) === 1 && lf_invite_find($pdo, $invite) !== null);
check('only its hash is stored', (int) one($pdo, "SELECT COUNT(*) FROM invites WHERE HEX(token_hash) = '$invite'") === 0);
check('a made-up link is no invitation', lf_invite_find($pdo, str_repeat('0', 32)) === null && lf_invite_find($pdo, 'nope') === null);
check('a weak password does not use up the invitation', lf_invite_accept($pdo, $invite, 'newbie', 'short', 'short')[0] !== null && lf_invite_find($pdo, $invite) !== null);
check('a taken, reserved or odd username is refused', lf_invite_accept($pdo, $invite, 'alice', 'a fine password', 'a fine password')[0] === 'That name is already taken.'
    && lf_invite_accept($pdo, $invite, 'staff', 'a fine password', 'a fine password')[0] === $reserved
    && lf_invite_accept($pdo, $invite, 'x y', 'a fine password', 'a fine password')[0] !== null);
[$problem, $newId] = lf_invite_accept($pdo, $invite, 'Newbie', 'a fine password', 'a fine password');
check('following the link makes a member', $problem === null && lf_member_get($pdo, $newId)['username'] === 'Newbie' && lf_member_get($pdo, $newId)['role'] === 'member' && lf_member_get($pdo, $newId)['account_id'] === 1);
check('the new member can log in', lf_login($pdo, 'newbie', 'a fine password', '203.0.113.9')[0] === null);
check('a link works once', lf_invite_find($pdo, $invite) === null && lf_invite_accept($pdo, $invite, 'Second', 'a fine password', 'a fine password')[0] !== null);
[, $modInvite] = lf_invite_create($pdo, $boss, 'moderator');
[, $modId] = lf_invite_accept($pdo, $modInvite, 'NewMod', 'a fine password', 'a fine password');
check('an invitation can make a moderator', lf_member_get($pdo, $modId)['role'] === 'moderator');
[, $old] = lf_invite_create($pdo, $boss);
$pdo->prepare("UPDATE invites SET expires_at = :t WHERE token_hash = UNHEX(SHA2(:x, 256))")->execute(['t' => ago(60), 'x' => $old]);
check('an expired link is refused', lf_invite_find($pdo, $old) === null && lf_invite_accept($pdo, $old, 'Late', 'a fine password', 'a fine password')[0] !== null);
[, $spare] = lf_invite_create($pdo, $boss);
$openBefore = count(lf_invites_open($pdo, 1));
check('open invitations are listed, and can be taken back', $openBefore >= 1 && lf_invite_revoke($pdo, $alice, (int) lf_invites_open($pdo, 1)[0]['id']) === 'Only the owner can do that.'
    && lf_invite_revoke($pdo, $boss, (int) lf_invites_open($pdo, 1)[0]['id']) === null && count(lf_invites_open($pdo, 1)) === $openBefore - 1);
[, $days] = lf_invite_create($pdo, $boss, 'member', 500);
check('an invitation lasts at most 90 days', strtotime((string) one($pdo, "SELECT MAX(expires_at) FROM invites") . ' UTC') - time() <= 90 * 86400 + 5);
$seats = lf_account_seats($pdo, 1);
$pdo->prepare("UPDATE accounts SET member_limit = :l WHERE id = 1")->execute(['l' => $seats['used']]);
[, $late] = [null, $days];
check('an account with no free places can’t invite or accept', lf_account_full($pdo, 1) && lf_invite_create($pdo, $boss)[0] === 'This account has no free places left.'
    && lf_invite_accept($pdo, $late, 'Overflow', 'a fine password', 'a fine password')[0] === 'This account has no free places left.');
$pdo->exec("UPDATE accounts SET member_limit = NULL WHERE id = 1");
check('with no limit there are always places', !lf_account_full($pdo, 1));

echo "Choosing a username\n";
$id = lf_member_create($pdo, 1, null, null, 'member', 'host-user-1');
check('a member without a username can’t write yet', !lf_can_write(me($pdo, $id) + ['username' => null]));
check('they choose one', lf_member_set_username($pdo, $id, 'x') !== null && lf_member_set_username($pdo, $id, 'Alice') === 'That name is already taken.'
    && lf_member_set_username($pdo, $id, 'Chosen') === null && me($pdo, $id)['username'] === 'Chosen');
check('and can’t change it afterwards', lf_member_set_username($pdo, $id, 'Another') === 'You already have a username.');
check('a login can’t be made for someone with no password', lf_login($pdo, 'Chosen', '', '203.0.113.1')[0] !== null);
check('a host’s own id is unique', (function () use ($pdo) {
    try {
        lf_member_create($pdo, 1, null, null, 'member', 'host-user-1');
        return false;
    } catch (PDOException $e) {
        return lf_is_duplicate($e);
    }
})());

echo "Members\n";
$mod = mk($pdo, 'Moddy', 'moderator');
$bob = mk($pdo, 'Bob');
$carol = mk($pdo, 'Carol');
check('a moderator can mute a member and lift it', lf_member_mute($pdo, $mod, (int) $bob['id'], true) === null && me($pdo, (int) $bob['id'])['status'] === 'muted'
    && lf_member_mute($pdo, $mod, (int) $bob['id'], false) === null && me($pdo, (int) $bob['id'])['status'] === 'active');
check('a member can’t mute anyone', lf_member_mute($pdo, $carol, (int) $bob['id'], true) === 'Only moderators can do that.');
check('nobody mutes themselves, the owner or a moderator', lf_member_mute($pdo, $mod, (int) $mod['id'], true) === 'You can’t do that to yourself.'
    && lf_member_mute($pdo, $mod, (int) $boss['id'], true) === 'The owner can’t be changed.' && lf_member_mute($pdo, $boss, (int) $mod['id'], true) !== null
    && lf_member_mute($pdo, $mod, 999999, true) === 'That member doesn’t exist.');
check('only the owner changes roles', lf_member_set_role($pdo, $mod, (int) $bob['id'], 'moderator') === 'Only the owner can do that.'
    && lf_member_set_role($pdo, $boss, (int) $bob['id'], 'owner') === 'Unknown role.' && lf_member_set_role($pdo, $boss, (int) $boss['id'], 'member') !== null);
lf_member_mute($pdo, $mod, (int) $bob['id'], true);
check('the owner makes a member a moderator, which also lifts a mute, and back', lf_member_set_role($pdo, $boss, (int) $bob['id'], 'moderator') === null
    && me($pdo, (int) $bob['id'])['role'] === 'moderator' && me($pdo, (int) $bob['id'])['status'] === 'active'
    && lf_member_set_role($pdo, $boss, (int) $bob['id'], 'member') === null && me($pdo, (int) $bob['id'])['role'] === 'member');
$leaver = mk($pdo, 'Leaver');
[, $leaverToken] = lf_login($pdo, 'leaver', 'password-for-tests', '203.0.113.4');
check('only the owner removes people', lf_member_remove($pdo, $mod, (int) $leaver['id']) === 'Only the owner can do that.' && lf_member_remove($pdo, $boss, (int) $boss['id']) !== null);
check('removing wipes the name, password and logins', lf_member_remove($pdo, $boss, (int) $leaver['id']) === null && lf_session_lookup($pdo, $leaverToken) === null
    && me($pdo, (int) $leaver['id'])['status'] === 'removed' && me($pdo, (int) $leaver['id'])['username'] === null
    && one($pdo, "SELECT password_hash FROM members WHERE id = {$leaver['id']}") === null);
check('a removed member can no longer log in, and shows as "Former member"', lf_login($pdo, 'leaver', 'password-for-tests', '203.0.113.4')[0] !== null && lf_display_name(null, 'removed') === 'Former member' && lf_display_name('Alice', 'active') === 'Alice');
check('their name can be taken again', lf_username_problem($pdo, 'Leaver') === null);
check('the member list leaves out people who left', !in_array('Leaver', array_column(lf_members_list($pdo), 'username'), true) && lf_members_list($pdo)[0]['role'] === 'owner');

echo "Permissions\n";
$staffOnly = lf_category_by_slug($pdo, 'announcements');
$help = lf_category_by_slug($pdo, 'help');
check('members start threads in ordinary categories only', lf_can_start_thread($carol, $help) && !lf_can_start_thread($carol, $staffOnly) && lf_can_start_thread($mod, $staffOnly) && lf_can_start_thread($boss, $staffOnly));
$muted = ['status' => 'muted'] + $carol;
check('a muted member can’t write', !lf_can_write($muted) && !lf_can_start_thread($muted, $help));
check('a locked thread takes replies from staff only', lf_can_reply($carol, ['locked' => 0]) && !lf_can_reply($carol, ['locked' => 1]) && lf_can_reply($mod, ['locked' => 1]));
check('logged-out visitors can do nothing', !lf_can_write(null) && !lf_is_staff(null) && !lf_is_owner(null) && lf_is_staff($mod) && !lf_is_owner($mod));

echo "Categories\n";
check('only the owner adds categories', lf_category_add($pdo, $mod, 'General', '', false)[0] === 'Only the owner can do that.');
[$problem, $generalId] = lf_category_add($pdo, $boss, 'General chat', 'Anything at all', false);
check('a new category gets an address made from its name, last in the list', $problem === null && lf_category_get($pdo, $generalId)['slug'] === 'general-chat'
    && array_column(lf_categories($pdo), 'slug') === ['announcements', 'help', 'show-your-site', 'general-chat']);
[, $secondId] = lf_category_add($pdo, $boss, 'General Chat!', '', true);
check('a name that makes the same address gets a number', lf_category_get($pdo, $secondId)['slug'] === 'general-chat-2' && (int) lf_category_get($pdo, $secondId)['staff_only'] === 1);
check('names and descriptions are checked', lf_category_add($pdo, $boss, '   ', '', false)[0] !== null && lf_category_add($pdo, $boss, str_repeat('x', 61), '', false)[0] !== null
    && lf_category_add($pdo, $boss, 'Fine', str_repeat('x', 201), false)[0] !== null);
check('renaming keeps the address', lf_category_update($pdo, $boss, $generalId, 'Chat', 'Talk', true) === null && lf_category_get($pdo, $generalId)['slug'] === 'general-chat'
    && lf_category_get($pdo, $generalId)['name'] === 'Chat' && (int) lf_category_get($pdo, $generalId)['staff_only'] === 1);
lf_category_update($pdo, $boss, $generalId, 'Chat', 'Talk', false);
check('categories move up and down', lf_category_move($pdo, $boss, $generalId, -1) === null && array_column(lf_categories($pdo), 'slug') === ['announcements', 'help', 'general-chat', 'show-your-site', 'general-chat-2']
    && lf_category_move($pdo, $boss, $generalId, 1) === null && array_column(lf_categories($pdo), 'slug') === ['announcements', 'help', 'show-your-site', 'general-chat', 'general-chat-2']
    && lf_category_move($pdo, $mod, $generalId, 1) === 'Only the owner can do that.');
check('an empty category can be deleted', lf_category_delete($pdo, $boss, $secondId) === null && lf_category_get($pdo, $secondId) === null);

echo "Starting threads\n";
$ann = lf_category_by_slug($pdo, 'announcements');
$show = lf_category_by_slug($pdo, 'show-your-site');
check('a thread needs a category', lf_thread_create($pdo, $carol, 999999, 'A title', 'Body')[0] === 'Please choose a category.');
check('and a title and text', lf_thread_create($pdo, $carol, (int) $help['id'], 'ab', 'Body')[0] !== null && lf_thread_create($pdo, $carol, (int) $help['id'], 'A title', '   ')[0] !== null);
check('members can’t start announcements', lf_thread_create($pdo, $carol, (int) $ann['id'], 'Sneaky', 'Body')[0] === 'Only moderators can start threads in Announcements.');
check('muted members and members without a username can’t start threads', lf_thread_create($pdo, $muted, (int) $help['id'], 'A title', 'Body')[0] === 'You are muted, so you can read but not write.'
    && lf_thread_create($pdo, ['username' => null] + $carol, (int) $help['id'], 'A title', 'Body')[0] === 'Please choose a username first.');
$result = lf_thread_create($pdo, $carol, (int) $help['id'], "  How do I\n add   email? ", "Hello\r\nworld");
$t1 = tid($result);
$thread = lf_thread_get($pdo, $t1);
check('a thread is created with its first post', $result[0] === null && $thread['title'] === 'How do I add email?' && $thread['post_count'] === 1 && $thread['last_post_id'] !== null
    && $thread['category_name'] === 'Help and questions' && $thread['author'] === 'Carol' && $thread['author_role'] === 'member');
check('the first post is stored tidy', one($pdo, "SELECT body FROM posts WHERE thread_id = $t1") === "Hello\nworld");
check('the author has read it', lf_read_pointer($pdo, (int) $carol['id'], $t1) === (int) $thread['last_post_id']);
$announcement = tid(lf_thread_create($pdo, $mod, (int) $ann['id'], 'Welcome everyone', 'Glad you are here.'));
check('a moderator can start an announcement', $announcement > 0);

echo "Replying and reading\n";
$before = lf_thread_get($pdo, $t1);
$result = lf_reply_create($pdo, $bob, $t1, 'Try the webmail link.');
check('a reply is added', $result[0] === null && lf_thread_get($pdo, $t1)['post_count'] === 2 && lf_thread_get($pdo, $t1)['last_post_id'] === $result[1]);
check('replies need text, and a thread that exists', lf_reply_create($pdo, $bob, $t1, '  ')[0] === 'Please write something first.' && lf_reply_create($pdo, $bob, 999999, 'Hi')[0] === 'That thread doesn’t exist any more.');
check('muted members can’t reply', lf_reply_create($pdo, $muted, $t1, 'Hi')[0] === 'You are muted, so you can read but not write.');
$list = lf_thread_list($pdo, $carol, null, 1, 25);
$byId = array_column($list['threads'], null, 'id');
check('the author sees new replies as unread, and their own thread’s author', $byId[$t1]['unread'] === true && $byId[$t1]['author'] === 'Carol' && $byId[$t1]['last_author'] === 'Bob' && $byId[$t1]['post_count'] === 2);
lf_mark_read($pdo, (int) $carol['id'], $t1, (int) lf_thread_get($pdo, $t1)['last_post_id']);
check('reading clears it', array_column(lf_thread_list($pdo, $carol, null, 1, 25)['threads'], null, 'id')[$t1]['unread'] === false);
check('a thread never opened is new for other members', array_column(lf_thread_list($pdo, $bob, null, 1, 25)['threads'], null, 'id')[$announcement]['unread'] === true);
$newcomer = mk($pdo, 'Newcomer');
$pdo->prepare("UPDATE members SET created_at = :t WHERE id = :m")->execute(['t' => ago(0), 'm' => $newcomer['id']]);
$newcomer = me($pdo, (int) $newcomer['id']);
$pdo->exec("UPDATE threads SET last_post_at = '" . ago(3600) . "'");
check('threads from before someone joined are not new to them', !array_column(lf_thread_list($pdo, $newcomer, null, 1, 25)['threads'], null, 'id')[$announcement]['unread']);
lf_mark_read($pdo, (int) $carol['id'], $t1, 1);
check('the reading mark never goes backwards', lf_read_pointer($pdo, (int) $carol['id'], $t1) === (int) lf_thread_get($pdo, $t1)['last_post_id']);
$page = lf_posts($pdo, $t1, 1, 25);
check('a thread’s posts come oldest first, with who wrote them', count($page['posts']) === 2 && $page['posts'][0]['username'] === 'Carol' && $page['posts'][1]['username'] === 'Bob'
    && $page['first_id'] === (int) $page['posts'][0]['id'] && $page['total'] === 2 && $page['pages'] === 1);

echo "Lists and pages\n";
$pdo->exec("UPDATE threads SET last_post_at = '" . ago(7200) . "'");
$pdo->prepare("UPDATE threads SET last_post_at = :t WHERE id = :i")->execute(['t' => ago(60), 'i' => $t1]);
$pdo->prepare("UPDATE threads SET pinned = 1 WHERE id = :i")->execute(['i' => $announcement]);
$ids = array_column(lf_thread_list($pdo, $carol, null, 1, 25)['threads'], 'id');
check('pinned threads come first, then the most recently active', $ids === [$announcement, $t1]);
check('a category lists only its own threads', array_column(lf_thread_list($pdo, $carol, (int) $help['id'], 1, 25)['threads'], 'id') === [$t1]);
$pdo->exec("UPDATE threads SET pinned = 0");
for ($i = 1; $i <= 5; $i++) {
    lf_thread_create($pdo, $bob, (int) $show['id'], "Site number $i", 'Look at it.');
}
$pageTwo = lf_thread_list($pdo, $carol, null, 2, 3);
check('long lists are split in pages, and a page past the end shows the last', $pageTwo['total'] === 7 && $pageTwo['pages'] === 3 && $pageTwo['page'] === 2 && count($pageTwo['threads']) === 3
    && lf_thread_list($pdo, $carol, null, 99, 3)['page'] === 3 && count(lf_thread_list($pdo, $carol, null, 99, 3)['threads']) === 1 && lf_thread_list($pdo, $carol, null, -4, 3)['page'] === 1);
for ($i = 1; $i <= 6; $i++) {
    lf_reply_create($pdo, $carol, $t1, "Reply $i");
}
$posts = lf_posts($pdo, $t1, 2, 3);
check('posts are paged too', $posts['total'] === 8 && $posts['pages'] === 3 && $posts['page'] === 2 && count($posts['posts']) === 3);

echo "Moderating\n";
check('only moderators pin and lock', lf_thread_set($pdo, $carol, $t1, 'pinned', true) === 'Only moderators can do that.' && lf_thread_set($pdo, $mod, $t1, 'nonsense', true) === 'Unknown action.'
    && lf_thread_set($pdo, $mod, 999999, 'pinned', true) === 'That thread doesn’t exist any more.');
check('a moderator pins and unpins', lf_thread_set($pdo, $mod, $t1, 'pinned', true) === null && lf_thread_get($pdo, $t1)['pinned'] === 1 && lf_thread_set($pdo, $mod, $t1, 'pinned', false) === null && lf_thread_get($pdo, $t1)['pinned'] === 0);
lf_thread_set($pdo, $mod, $t1, 'locked', true);
check('a locked thread refuses members’ replies but not staff’s', lf_reply_create($pdo, $bob, $t1, 'Hello?')[0] === 'This thread is locked.' && lf_reply_create($pdo, $mod, $t1, 'Closing this.')[0] === null);
lf_thread_set($pdo, $mod, $t1, 'locked', false);
check('unlocking lets replies in again', lf_reply_create($pdo, $bob, $t1, 'Thanks.')[0] === null);
check('a thread moves to another category', lf_thread_move($pdo, $carol, $t1, (int) $show['id']) === 'Only moderators can do that.' && lf_thread_move($pdo, $mod, $t1, 999999) === 'Please choose a category.'
    && lf_thread_move($pdo, $mod, $t1, (int) $show['id']) === null && lf_thread_get($pdo, $t1)['category_slug'] === 'show-your-site');
lf_thread_move($pdo, $mod, $t1, (int) $help['id']);

echo "Reports\n";
$carolPost = (int) lf_posts($pdo, $t1, 1, 25)['posts'][0]['id'];
$bobPost = (int) $pdo->query("SELECT MIN(id) FROM posts WHERE thread_id = $t1 AND member_id = {$bob['id']}")->fetchColumn();
check('a member reports a post', lf_report_add($pdo, $bob, $carolPost) === null && lf_reports_open_count($pdo) === 1);
check('you can’t report yourself, or something that isn’t there, or when muted', lf_report_add($pdo, $carol, $carolPost) === 'You can’t report your own post.'
    && lf_report_add($pdo, $bob, 999999) === 'That post doesn’t exist any more.' && lf_report_add($pdo, $muted, $carolPost) !== null);
check('reporting again changes nothing, and says nothing', lf_report_add($pdo, $bob, $carolPost) === null && lf_reports_open($pdo)[0]['reports'] === 1);
lf_report_add($pdo, $newcomer, $carolPost);
$open = lf_reports_open($pdo);
check('reports on one post are added up, and show the author but not the reporters', count($open) === 1 && $open[0]['reports'] === 2 && $open[0]['username'] === 'Carol' && $open[0]['thread_id'] === $t1
    && !array_key_exists('reporter', $open[0]) && lf_reports_open_count($pdo) === 1);
check('only moderators close reports', lf_report_resolve($pdo, $carol, $carolPost) === 'Only moderators can do that.' && lf_report_resolve($pdo, $mod, $carolPost) === null && lf_reports_open_count($pdo) === 0);
check('a new report opens it again', lf_report_add($pdo, $bob, $carolPost) === null && lf_reports_open_count($pdo) === 1);

echo "Deleting\n";
check('members can only delete their own posts', lf_post_delete($pdo, $carol, $bobPost)[0] === 'You can only delete your own posts.');
$extra = lf_reply_create($pdo, $bob, $t1, 'A reply to delete');
$countBefore = lf_thread_get($pdo, $t1)['post_count'];
[$problem, $threadId, $gone] = lf_post_delete($pdo, $bob, (int) $extra[1]);
$after = lf_thread_get($pdo, $t1);
check('a member deletes their own reply, and the thread is added up again', $problem === null && $threadId === $t1 && !$gone && $after['post_count'] === $countBefore - 1 && (int) $after['last_post_id'] !== (int) $extra[1]);
check('deleted posts are out of sight', !in_array((int) $extra[1], array_column(lf_posts($pdo, $t1, 1, 50)['posts'], 'id'), true) && lf_post_delete($pdo, $bob, (int) $extra[1])[0] === 'That post doesn’t exist any more.');
$stale = lf_thread_get($pdo, $t1);
check('the thread’s latest activity follows the last remaining post', $stale['last_post_at'] === one($pdo, "SELECT created_at FROM posts WHERE id = {$stale['last_post_id']}"));
lf_report_add($pdo, $carol, $bobPost);
check('a moderator deletes anyone’s post, which closes the reports on it', lf_reports_open_count($pdo) === 2 && lf_post_delete($pdo, $mod, $bobPost)[0] === null && lf_reports_open_count($pdo) === 1);
check('deleting the first post deletes the thread when nobody else has replied', ($r = lf_thread_create($pdo, $bob, (int) $help['id'], 'Solo thread', 'Only me')) && lf_post_delete($pdo, $bob, (int) one($pdo, "SELECT id FROM posts WHERE thread_id = {$r[1]}"))[2] === true && lf_thread_get($pdo, (int) $r[1]) === null);
$r = lf_thread_create($pdo, $bob, (int) $help['id'], 'Busy thread', 'First');
lf_reply_create($pdo, $carol, (int) $r[1], 'Second');
check('an author can’t delete a thread others have replied to', lf_thread_delete($pdo, $bob, (int) $r[1]) === 'Other members have replied, so a moderator has to remove this thread.'
    && lf_post_delete($pdo, $bob, (int) one($pdo, "SELECT MIN(id) FROM posts WHERE thread_id = {$r[1]}"))[0] !== null && lf_thread_get($pdo, (int) $r[1]) !== null);
check('nobody else can delete it either, but a moderator can', lf_thread_delete($pdo, $carol, (int) $r[1]) === 'You can only delete your own threads.' && lf_thread_delete($pdo, $mod, (int) $r[1]) === null && lf_thread_get($pdo, (int) $r[1]) === null);
check('deleted threads leave the lists', !in_array((int) $r[1], array_column(lf_thread_list($pdo, $carol, null, 1, 50)['threads'], 'id'), true));
check('a deleted thread can’t be replied to', lf_reply_create($pdo, $carol, (int) $r[1], 'Hello?')[0] === 'That thread doesn’t exist any more.');

echo "Limits\n";
$pace = mk($pdo, 'Pacer');
cfg(['limits' => ['seconds_between_posts' => 60]]);
check('members must pause between posts', lf_reply_create($pdo, $pace, $t1, 'One')[0] === null && lf_reply_create($pdo, $pace, $t1, 'Two')[0] === 'Please wait a few seconds before posting again.');
check('moderators are never limited', lf_reply_create($pdo, $mod, $t1, 'One')[0] === null && lf_reply_create($pdo, $mod, $t1, 'Two')[0] === null);
cfg(['limits' => ['posts_per_hour' => 2]]);
$fast = mk($pdo, 'Fast');
lf_reply_create($pdo, $fast, $t1, 'One');
lf_reply_create($pdo, $fast, $t1, 'Two');
check('there is a limit of posts per hour', str_contains((string) lf_reply_create($pdo, $fast, $t1, 'Three')[0], 'limit of 2 posts an hour'));
check('deleting posts doesn’t get round it', (function () use ($pdo, $fast, $t1) {
    $id = (int) one($pdo, "SELECT MAX(id) FROM posts WHERE member_id = {$fast['id']}");
    lf_post_delete($pdo, $fast, $id);
    return lf_reply_create($pdo, $fast, $t1, 'Four')[0] !== null;
})());
cfg(['limits' => ['threads_per_day' => 1]]);
$starter = mk($pdo, 'Starter');
check('there is a limit of new threads per day', lf_thread_create($pdo, $starter, (int) $help['id'], 'First one', 'Text')[0] === null
    && str_contains((string) lf_thread_create($pdo, $starter, (int) $help['id'], 'Second one', 'Text')[0], 'threads in the last day')
    && lf_thread_create($pdo, $mod, (int) $help['id'], 'Mod one', 'Text')[0] === null && lf_thread_create($pdo, $mod, (int) $help['id'], 'Mod two', 'Text')[0] === null);
cfg([]);

echo "Housekeeping\n";
$pdo->exec("DELETE FROM sessions");
$old = lf_session_start($pdo, (int) $carol['id']);
$fresh = lf_session_start($pdo, (int) $carol['id']);
$pdo->prepare("UPDATE sessions SET last_used_at = :t WHERE token_hash = :h")->execute(['t' => ago(40 * 86400), 'h' => lf_token_hash($old)]);
$pdo->prepare("INSERT INTO login_failures (who, at) VALUES (:w, :t)")->execute(['w' => str_repeat('a', 8), 't' => ago(2 * 86400)]);
$pdo->prepare("INSERT INTO login_failures (who, at) VALUES (:w, :t)")->execute(['w' => str_repeat('b', 8), 't' => ago(60)]);
[, $usedInvite] = lf_invite_create($pdo, $boss);
[, $freshInvite] = lf_invite_create($pdo, $boss);
$pdo->prepare("UPDATE invites SET used_at = :t WHERE token_hash = :h")->execute(['t' => ago(40 * 86400), 'h' => lf_token_hash($usedInvite)]);
$oldThread = tid(lf_thread_create($pdo, $mod, (int) $help['id'], 'Old deleted thread', 'Gone'));
$recentThread = tid(lf_thread_create($pdo, $mod, (int) $help['id'], 'Recently deleted thread', 'Gone'));
lf_thread_delete($pdo, $mod, $oldThread);
lf_thread_delete($pdo, $mod, $recentThread);
$pdo->prepare("UPDATE threads SET deleted_at = :t WHERE id = :i")->execute(['t' => ago(31 * 86400), 'i' => $oldThread]);
$oldPost = (int) one($pdo, "SELECT MAX(id) FROM posts WHERE thread_id = $t1 AND member_id = {$carol['id']}");
lf_post_delete($pdo, $carol, $oldPost);
$pdo->prepare("UPDATE posts SET deleted_at = :t WHERE id = :i")->execute(['t' => ago(31 * 86400), 'i' => $oldPost]);
$log = lf_maintain($pdo);
check('old logins go and recent ones stay', lf_session_lookup($pdo, $old) === null && lf_session_lookup($pdo, $fresh) !== null);
check('old login failures go', (int) one($pdo, "SELECT COUNT(*) FROM login_failures WHERE who = " . "'" . str_repeat('a', 8) . "'") === 0 && (int) one($pdo, "SELECT COUNT(*) FROM login_failures WHERE who = '" . str_repeat('b', 8) . "'") === 1);
check('used-up invitations go and open ones stay', lf_invite_find($pdo, $freshInvite) !== null && (int) one($pdo, "SELECT COUNT(*) FROM invites WHERE token_hash = UNHEX('" . bin2hex(lf_token_hash($usedInvite)) . "')") === 0);
check('things deleted more than 30 days ago are purged for good, newer ones wait', (int) one($pdo, "SELECT COUNT(*) FROM threads WHERE id = $oldThread") === 0
    && (int) one($pdo, "SELECT COUNT(*) FROM threads WHERE id = $recentThread") === 1 && (int) one($pdo, "SELECT COUNT(*) FROM posts WHERE id = $oldPost") === 0 && (int) one($pdo, "SELECT COUNT(*) FROM posts WHERE thread_id = $oldThread") === 0);
check('it says what it did', count($log) === 5 && str_contains($log[0], 'expired logins'));

echo "Privacy\n";
$dump = '';
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $dump .= json_encode($pdo->query("SELECT * FROM `$table`")->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE);
}
check('no IP address is stored anywhere', !str_contains($dump, '203.0.113') && !str_contains($dump, '198.51.100'));
check('no table has a column for an address or a browser', (int) one($pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name IN ('ip', 'ip_address', 'address', 'remote_addr', 'user_agent', 'ua', 'email')") === 0);
check('no password is stored as it was typed', !str_contains($dump, 'password-for-tests') && !str_contains($dump, 'correct horse battery') && !str_contains($dump, $token));

echo "Themes\n";
check('the default theme is used', lf_theme() === 'default');
$missing = array_filter(['layout', 'bar', 'login', 'setup', 'welcome', 'list', 'thread', 'new', 'settings', 'rules', 'manage', 'reports', 'error'], fn ($name) => lf_theme_file('templates', "$name.php") === null);
check('every page has a template' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''), $missing === []);
check('assets are versioned', preg_match('~asset\.php\?f=theme\.css&v=\d+$~', lf_asset('theme.css')) === 1);
check('an unknown or odd theme name falls back to the default', (function () {
    cfg(['theme' => '../etc']);
    $a = lf_theme();
    cfg(['theme' => 'does-not-exist']);
    $b = lf_theme();
    cfg([]);
    return $a === 'default' && $b === 'default';
})());


echo "Custom themes\n";
$themes = sys_get_temp_dir() . '/lf-themes-' . bin2hex(random_bytes(4));
mkdir("$themes/mine/assets", 0777, true);
mkdir("$themes/mine/templates", 0777, true);
file_put_contents("$themes/mine/assets/custom.css", ':root { --lf-accent: red; }');
file_put_contents("$themes/mine/templates/rules.php", 'MY RULES');
cfg(['theme' => 'mine', 'theme_paths' => [$themes]]);
ob_start();
lf_render('rules', ['rules' => []]);
$mine = (string) ob_get_clean();
check('a theme found in theme_paths can add a stylesheet and replace a page', lf_theme() === 'mine' && lf_theme_file('assets', 'custom.css', true) === "$themes/mine/assets/custom.css" && $mine === 'MY RULES');
check('everything else still comes from the default theme', realpath((string) lf_theme_file('assets', 'theme.css')) === realpath(LF_ROOT . '/themes/default/assets/theme.css')
    && realpath((string) lf_theme_file('templates', 'list.php')) === realpath(LF_ROOT . '/themes/default/templates/list.php') && lf_theme_file('assets', 'custom.css', false) !== null && lf_theme_file('assets', 'nothing.css') === null);
foreach (['assets/custom.css', 'templates/rules.php'] as $file) {
    unlink("$themes/mine/$file");
}
rmdir("$themes/mine/assets");
rmdir("$themes/mine/templates");
rmdir("$themes/mine");
rmdir($themes);
cfg([]);

echo "Addresses and times\n";
$saved = $_SERVER;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/t/12?page=2';
check('at the top of a domain, addresses have no folder', lf_base() === '' && lf_url('t/1') === '/t/1' && lf_url() === '/' && lf_route_path() === '/t/12');
$_SERVER['SCRIPT_NAME'] = '/forum/index.php';
$_SERVER['REQUEST_URI'] = '/forum/c/help?page=2';
check('in a sub-folder, the folder is added to addresses and taken off the page asked for', lf_base() === '/forum' && lf_url('t/1') === '/forum/t/1' && lf_url() === '/forum/' && lf_route_path() === '/c/help');
$_SERVER['REQUEST_URI'] = '/forum/';
check('the front page is /', lf_route_path() === '/');
$_SERVER['REQUEST_URI'] = '/forum/login';
$_SERVER['HTTP_HOST'] = 'example.test';
ob_start();
lf_dispatch($pdo, 'GET', lf_route_path());
$login = (string) ob_get_clean();
check('a whole page in a sub-folder links everything inside the folder', str_contains($login, 'action="/forum/login"') && str_contains($login, 'href="/forum/asset.php?f=theme.css&amp;v=')
    && !preg_match('~(href|src|action)="/(?!forum/)~', $login));
check('invitation links use the address the forum is asked at', lf_abs_url('invite/x') === 'http://example.test/forum/invite/x' && (function () {
    cfg(['url' => 'https://forum.example/']);
    $a = lf_abs_url('invite/x');
    cfg([]);
    return $a === 'https://forum.example/invite/x';
})());
$_SERVER = $saved;
check('HTTPS is known from the server, or from a trusted proxy', lf_is_https(['HTTPS' => 'on']) && !lf_is_https(['HTTPS' => 'off']) && !lf_is_https([])
    && lf_is_https(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']) && !lf_is_https(['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_PROTO' => 'https']));
$now = strtotime('2026-09-29 12:00:00 UTC');
check('times are shown as "5 min ago", "3 hours ago" and, after a week, as dates', lf_ago('2026-09-29 11:59:40', $now) === 'just now' && lf_ago('2026-09-29 11:55:00', $now) === '5 min ago'
    && lf_ago('2026-09-29 11:00:00', $now) === '1 hour ago' && lf_ago('2026-09-29 09:00:00', $now) === '3 hours ago' && lf_ago('2026-09-28 12:00:00', $now) === '1 day ago'
    && lf_ago('2026-09-22 12:00:00', $now) === 'Sep 22' && lf_ago('2025-12-25 12:00:00', $now) === 'Dec 25, 2025' && lf_ago('2026-09-29 12:05:00', $now) === 'just now');
check('and as machine-readable times', lf_iso('2026-09-29 12:00:00') === '2026-09-29T12:00:00Z');

echo "\n", $passed, ' passed, ', $failed, " failed\n";
exit($failed ? 1 : 0);
