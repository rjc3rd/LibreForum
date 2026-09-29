<?php
// End-to-end checks. Starts PHP's built-in web server on the e2e database (wiped first) and uses the forum
// through HTTP the way browsers do: cookies, forms, redirects.
//   php tests/e2e.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/setup.php';
require_once __DIR__ . '/../lib/host.php';

if (!is_file(__DIR__ . '/config.php')) {
    exit("tests/config.php is missing. Copy tests/config.example.php and fill it in.\n");
}
$t = require __DIR__ . '/config.php';
if (!str_ends_with((string) $t['e2e_db'], '_e2e')) {
    exit("Refusing to wipe {$t['e2e_db']}: the e2e database's name must end in _e2e.\n");
}
$db = ['host' => $t['host'], 'name' => $t['e2e_db'], 'user' => $t['user'], 'pass' => $t['pass']];
$pdo = lf_connect($db);
$wipe = function () use ($pdo) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE `$table`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
};
$wipe();

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

// ---------- the command-line tools ----------
// They read their settings from a temporary file (LIBREFORUM_CONFIG), which points at the e2e database.
$settings = ['db' => $db, 'url' => 'https://forum.example', 'name' => 'CLI Forum', 'reserved_names' => [], 'limits' => ['posts_per_hour' => 1000, 'threads_per_day' => 1000, 'seconds_between_posts' => 0],
    'session_days' => 30, 'trusted_proxies' => [], 'theme' => 'default', 'theme_paths' => []];
lf_config($settings);
$cliConfig = (string) tempnam(sys_get_temp_dir(), 'lf-cli');
chmod($cliConfig, 0600);
file_put_contents($cliConfig, '<?php return ' . var_export($settings, true) . ';');
register_shutdown_function(fn () => @unlink($cliConfig));
// Runs bin/<script>.php with the arguments, feeding $stdin (passwords). Returns [exit code, output].
function cli(string $script, array $args = [], string $stdin = ''): array
{
    global $cliConfig;
    $process = proc_open([PHP_BINARY, LF_ROOT . "/bin/$script.php", ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, LF_ROOT,
        array_merge(getenv(), ['LIBREFORUM_CONFIG' => $cliConfig]));
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), trim($out)];
}
$passwordTwice = "correct horse battery\ncorrect horse battery\n";

echo "Command-line tools\n";
[$code, $out] = cli('install');
check('install creates the tables', $code === 0 && str_contains($out, 'Tables ready') && (int) one($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()") === 13);
check('tools that act for the owner say when there is none', str_contains(cli('invite')[1], 'There is no owner yet') && str_contains(cli('category', ['list'])[1], 'There is no owner yet') && str_contains(cli('member', ['list'])[1], 'There is no owner yet'));
check('owner explains how to use it', str_contains(cli('owner', ['cliboss'])[1], 'Usage'));
check('a bad password stops it', cli('owner', ['cliboss', 'CLI Forum'], "short\nshort\n")[0] === 1 && (int) one($pdo, "SELECT COUNT(*) FROM members") === 0);
[$code, $out] = cli('owner', ['cliboss', 'CLI Forum'], $passwordTwice);
check('owner creates the owner and the first categories', $code === 0 && str_contains($out, 'Done.') && one($pdo, "SELECT role FROM members WHERE username = 'cliboss'") === 'owner' && (int) one($pdo, "SELECT COUNT(*) FROM categories") === 3);
[$code, $out] = cli('owner', ['other', 'Second'], $passwordTwice);
check('owner works once', $code === 1 && str_contains($out, 'already been set up'));

[$code, $out] = cli('invite');
check('invite prints a full link', $code === 0 && preg_match('~^https://forum\.example/invite/[0-9a-f]{32}$~', $out) === 1);
[$problem, $mayaId] = lf_invite_accept($pdo, substr($out, -32), 'Maya', 'maya password 1', 'maya password 1');
check('and the link works', $problem === null && (int) $mayaId > 0);
check('invitations can be for moderators, and only for real roles', cli('invite', ['moderator', '30'])[0] === 0 && str_contains(cli('invite', ['owner'])[1], 'Unknown role.') && cli('invite', ['owner'])[0] === 1);

check('category list shows the categories', str_contains(cli('category', ['list'])[1], 'help') && str_contains(cli('category', ['list'])[1], 'only moderators start threads'));
check('a category can be added', cli('category', ['add', 'Off topic', 'Anything else', 'staff-only'])[1] === 'Added.' && (int) one($pdo, "SELECT staff_only FROM categories WHERE slug = 'off-topic'") === 1);
check('renamed, opened up, moved and deleted', cli('category', ['rename', 'off-topic', 'Chatter'])[1] === 'Renamed.' && one($pdo, "SELECT name FROM categories WHERE slug = 'off-topic'") === 'Chatter'
    && cli('category', ['staff-only', 'off-topic', 'off'])[1] === 'Saved.' && (int) one($pdo, "SELECT staff_only FROM categories WHERE slug = 'off-topic'") === 0
    && cli('category', ['up', 'off-topic'])[1] === 'Moved.' && cli('category', ['delete', 'off-topic'])[1] === 'Deleted.' && (int) one($pdo, "SELECT COUNT(*) FROM categories WHERE slug = 'off-topic'") === 0);
check('an unknown category is explained', str_contains(cli('category', ['delete', 'nope'])[1], 'There is no category'));

check('member list shows everyone', str_contains(cli('member', ['list'])[1], 'cliboss') && str_contains(cli('member', ['list'])[1], 'Maya'));
check('muting and unmuting', cli('member', ['mute', 'maya'])[1] === 'Done.' && one($pdo, "SELECT status FROM members WHERE id = $mayaId") === 'muted' && cli('member', ['unmute', 'maya'])[1] === 'Done.' && one($pdo, "SELECT status FROM members WHERE id = $mayaId") === 'active');
check('roles change', cli('member', ['moderator', 'maya'])[1] === 'Done.' && one($pdo, "SELECT role FROM members WHERE id = $mayaId") === 'moderator' && cli('member', ['member', 'maya'])[0] === 0 && one($pdo, "SELECT role FROM members WHERE id = $mayaId") === 'member');
[, $token] = lf_login($pdo, 'maya', 'maya password 1', '198.51.100.1');
check('a lost password can be replaced, and old logins end', cli('member', ['password', 'maya'], "new maya password\nnew maya password\n")[1] === 'Password changed. Any logins they had were ended.'
    && lf_session_lookup($pdo, (string) $token) === null && lf_login($pdo, 'maya', 'new maya password', '198.51.100.1')[0] === null && lf_login($pdo, 'maya', 'maya password 1', '198.51.100.1')[0] !== null);
check('a mismatched new password is refused', cli('member', ['password', 'maya'], "new maya password\nnew maya passworX\n")[0] === 1);
check('removing a member, and unknown members', cli('member', ['remove', 'maya'])[1] === 'Done.' && one($pdo, "SELECT status FROM members WHERE id = $mayaId") === 'removed' && str_contains(cli('member', ['mute', 'nobody'])[1], 'There is no member called nobody.'));
check('the owner can’t be removed from here either', cli('member', ['remove', 'cliboss'])[0] === 1);

check('maintain says what it did, or nothing with --quiet', str_contains(cli('maintain')[1], 'expired logins removed') && cli('maintain', ['--quiet'])[1] === '');

$wipe();
cli('install');
[$code, $out] = cli('owner', ['ranzy', 'Host Forum', '--host-ref=2']);
check('an owner for a forum run inside another app is made with no password', $code === 0 && str_contains($out, 'no password') && one($pdo, "SELECT password_hash FROM members WHERE username = 'ranzy'") === null
    && one($pdo, "SELECT host_ref FROM members WHERE username = 'ranzy'") === '2' && one($pdo, "SELECT role FROM members WHERE username = 'ranzy'") === 'owner' && (int) one($pdo, "SELECT COUNT(*) FROM categories") === 3);
check('that works once', cli('owner', ['other', 'Second', '--host-ref=3'])[0] === 1 && str_contains(cli('owner', ['other', 'Second', '--host-ref=3'])[1], 'already been set up'));
$wipe();
cli('install');
check('a bad host id is refused', str_contains(cli('owner', ['ranzy', 'Host Forum', '--host-ref=bad id!'])[1], 'That host id isn’t valid.') && (int) one($pdo, "SELECT COUNT(*) FROM members") === 0);
$wipe();
cli('install');
[$code, $out] = cli('demo');
check('the demo fills an empty forum', $code === 0 && str_contains($out, 'demo-password-123') && (int) one($pdo, "SELECT COUNT(*) FROM threads") === 7 && (int) one($pdo, "SELECT COUNT(*) FROM members") === 6
    && (int) one($pdo, "SELECT COUNT(*) FROM posts") === 20 && (int) one($pdo, "SELECT COUNT(*) FROM reports") === 1);
check('its people can log in', lf_login($pdo, 'maya', 'demo-password-123', '198.51.100.2')[0] === null && lf_login($pdo, 'demo_owner', 'demo-password-123', '198.51.100.2')[0] === null);
check('and it won’t fill a forum that is in use', str_contains(cli('demo')[1], 'already has threads'));

// ---------- a fresh forum for the web pages ----------
$wipe();
lf_install_schema($pdo);

// ---------- the server ----------
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
fclose($probe);
$log = (string) tempnam(sys_get_temp_dir(), 'lf-e2e');
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', LF_ROOT . '/public', __DIR__ . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
register_shutdown_function(function () use ($server, $log) {
    proc_terminate($server);
    @unlink($log);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$base = "http://127.0.0.1:$port";

// ---------- a browser ----------
$pages = [];
final class Browser
{
    private CurlHandle $ch;

    public function __construct(private string $base)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 30]);
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->send('GET', $path, null, $headers);
    }

    public function post(string $path, array $fields, array $headers = []): array
    {
        return $this->send('POST', $path, $fields, $headers);
    }

    // Does what a browser does after a redirect: asks for the new address.
    public function follow(array $res): array
    {
        return $this->get(preg_replace('~#.*$~', '', (string) $res['location']));
    }

    private function send(string $method, string $path, ?array $fields, array $headers): array
    {
        global $pages;
        curl_setopt($this->ch, CURLOPT_URL, $this->base . $path);
        curl_setopt($this->ch, CURLOPT_HTTPGET, $method === 'GET');
        if ($method === 'POST') {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
        }
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge(['Expect:'], $headers));
        $raw = (string) curl_exec($this->ch);
        $size = (int) curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $size);
        $res = ['status' => (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), 'head' => $head, 'body' => substr($raw, $size), 'headers' => [], 'location' => null];
        foreach (explode("\r\n", $head) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $res['headers'][strtolower($name)] = trim($value);
            }
        }
        $res['location'] = $res['headers']['location'] ?? null;
        if (str_starts_with($res['headers']['content-type'] ?? '', 'text/html')) {
            $pages[] = [$method . ' ' . $path, $res['body']];
        }
        return $res;
    }
}

function csrf(array $res): string
{
    return preg_match('~name="csrf" value="([0-9a-f]{32})"~', $res['body'], $m) ? $m[1] : '';
}
function flash(array $res): string
{
    return preg_match('~<p class="lf-flash lf-flash-(ok|error)" role="status">(.*?)</p>~s', $res['body'], $m) ? $m[1] . ': ' . html_entity_decode($m[2], ENT_QUOTES) : '';
}
function has(array $res, string $needle): bool
{
    return str_contains($res['body'], $needle);
}
function to(array $res, string $path): bool
{
    return $res['status'] === 303 && $res['location'] === $path;
}
$guest = new Browser($base);
$owner = new Browser($base);
$alice = new Browser($base);
$origin = ["Origin: $base"];

echo "A new forum\n";
check('every address sends a new forum’s visitor to the setup page', to($guest->get('/'), '/setup') && to($guest->get('/login'), '/setup') && to($guest->get('/t/1'), '/setup'));
$res = $guest->get('/setup');
check('the setup page is shown', $res['status'] === 200 && has($res, 'Set up your forum'));
check('pages are sent with strict headers', str_contains($res['headers']['content-security-policy'], "default-src 'self'") && str_contains($res['headers']['content-security-policy'], "frame-ancestors 'none'")
    && $res['headers']['x-frame-options'] === 'DENY' && $res['headers']['referrer-policy'] === 'same-origin' && str_contains($res['headers']['x-robots-tag'], 'noindex')
    && $res['headers']['cache-control'] === 'no-store' && $res['headers']['x-content-type-options'] === 'nosniff');
$res = $guest->post('/setup', ['forum' => 'Our Forum', 'username' => 'Boss', 'password' => 'correct horse battery', 'password2' => 'correct horse batterX'], $origin);
check('a mismatched password shows the form again with what was typed', $res['status'] === 200 && has($res, 'The two passwords don’t match.') && has($res, 'value="Our Forum"') && has($res, 'value="Boss"') && !isset($res['headers']['set-cookie']));
$res = $guest->post('/setup', ['forum' => 'Our Forum', 'username' => 'Boss', 'password' => 'correct horse battery', 'password2' => 'correct horse battery'], ['Origin: https://evil.test']);
check('a form sent from another website is refused', $res['status'] === 200 && has($res, 'That form had expired') && (int) one($pdo, "SELECT COUNT(*) FROM members") === 0);
$res = $owner->post('/setup', ['forum' => 'Our Forum', 'username' => 'Boss', 'password' => 'correct horse battery', 'password2' => 'correct horse battery'], $origin);
check('setup creates the owner and logs them in', to($res, '/') && preg_match('~^set-cookie: libreforum=[0-9a-f]{64}; .*HttpOnly; SameSite=Lax~im', $res['head']) === 1);
$home = $owner->follow($res);
check('the first page welcomes them, with the first categories', $home['status'] === 200 && flash($home) === 'ok: Your forum is ready. Next: invite some people.' && has($home, '<h1>Latest threads</h1>')
    && has($home, 'Announcements') && has($home, 'Help and questions') && has($home, 'Show your site') && has($home, 'No threads here yet.'));
check('the login cookie is the only one, and it is not sent again', !isset($owner->get('/')['headers']['set-cookie']));
$res = $guest->get('/login', ['Cookie: libreforum=' . str_repeat('a', 64)]);
check('a login cookie that no longer exists is cleared', $res['status'] === 200 && preg_match('~^set-cookie: libreforum=deleted~im', $res['head']) === 1);
check('setup is gone once used', to($owner->get('/setup'), '/') && to($guest->get('/setup'), '/login'));

echo "Inviting\n";
$res = $owner->get('/manage/invites');
$csrf = csrf($res);
check('the owner sees the invitation page', $res['status'] === 200 && strlen($csrf) === 32 && has($res, 'Invite someone'));
$res = $owner->post('/manage/invites', ['role' => 'member', 'days' => '7'], $origin);
check('a form without the login’s csrf value is turned away', to($res, '/manage/invites') && flash($owner->follow($res)) === 'error: That form had expired. Please try again.');
$res = $owner->post('/manage/invites', ['csrf' => $csrf, 'role' => 'member', 'days' => '7'], $origin);
preg_match('~value="' . preg_quote($base, '~') . '/invite/([0-9a-f]{32})"~', $res['body'], $m);
$invite = $m[1] ?? '';
check('creating an invitation shows the link once', $res['status'] === 200 && has($res, 'Your invitation link') && strlen($invite) === 32);
check('it is not shown again', !has($owner->get('/manage/invites'), $invite));
$res = $alice->get("/invite/$invite");
check('the invitation page welcomes a newcomer, with the house rules', $res['status'] === 200 && has($res, 'Welcome to Our Forum') === false && has($res, '<h1>Welcome!</h1>') && has($res, 'Never post passwords') && has($res, 'name="password2"'));
$res = $alice->post("/invite/$invite", ['username' => 'Alice', 'password' => 'short', 'password2' => 'short'], $origin);
check('a weak password is refused and the link still works', $res['status'] === 200 && has($res, 'Please use a password of 10 characters or more.') && has($res, 'value="Alice"'));
$res = $alice->post("/invite/$invite", ['username' => 'boss', 'password' => 'alice password 1', 'password2' => 'alice password 1'], $origin);
check('a taken username is refused', $res['status'] === 200 && has($res, 'That name is already taken.'));
$res = $alice->post("/invite/$invite", ['username' => 'Alice', 'password' => 'alice password 1', 'password2' => 'alice password 1'], $origin);
check('following the link makes a member and logs them in', to($res, '/') && flash($alice->follow($res)) === 'ok: Welcome, Alice!');
$res = $guest->get("/invite/$invite");
check('the link works once', $res['status'] === 410 && has($res, 'expired or was already used'));
$aliceId = (int) one($pdo, "SELECT id FROM members WHERE username = 'Alice'");

echo "Starting and reading threads\n";
$res = $alice->get('/new?c=help');
$csrfA = csrf($res);
check('the new thread form preselects the category and hides staff-only ones', $res['status'] === 200 && has($res, 'selected>Help and questions') && !has($res, '>Announcements</option>'));
$title = 'Hello <b>world</b>';
$body = "First line\n\nCheck https://example.com/docs?a=1&b=2 and `ls -la`\n```\n<?php echo 'x'; ?>\n```\n<script>alert(1)</script>";
$res = $alice->post('/new', ['category' => (string) one($pdo, "SELECT id FROM categories WHERE slug = 'help'"), 'title' => $title, 'body' => $body], $origin);
check('a thread posted without the csrf value is turned away', to($res, '/new'));
$res = $alice->post('/new', ['csrf' => $csrfA, 'category' => (string) one($pdo, "SELECT id FROM categories WHERE slug = 'help'"), 'title' => $title, 'body' => $body], $origin);
check('a thread is posted', to($res, '/t/1'));
$res = $alice->follow($res);
check('and shown, with the message', flash($res) === 'ok: Thread posted.' && has($res, '<h1>Hello &lt;b&gt;world&lt;/b&gt;</h1>'));
check('HTML in titles and posts is only ever text', !has($res, 'Hello <b>world</b>') && has($res, '&lt;script&gt;alert(1)&lt;/script&gt;') && !has($res, '<script>alert(1)'));
check('links, `code` and code blocks are rendered', has($res, '<a href="https://example.com/docs?a=1&amp;b=2" rel="noopener noreferrer nofollow ugc">') && has($res, '<code>ls -la</code>')
    && has($res, '<pre><code>&lt;?php echo &#039;x&#039;; ?&gt;</code></pre>'));
check('a member sees no moderator tools', !has($res, 'Moderator tools') && has($res, 'Post reply'));
$res = $owner->get('/t/1');
check('a moderator does', has($res, 'Moderator tools') && has($res, 'Delete thread') && has($res, 'Pin</button>') && has($res, 'Lock</button>'));
$csrfO = csrf($res);
$res = $owner->post('/t/1/reply', ['csrf' => $csrfO, 'body' => 'Welcome aboard, Alice!'], $origin);
check('the owner replies and lands on the reply', to($res, '/t/1#post-2'));
$res = $alice->get('/');
check('the thread shows as new to Alice, with the last reply', has($res, 'lf-tag lf-tag-new') && has($res, 'Last reply by <b>Boss</b>') && has($res, 'Started by <b>Alice</b>'));
$res = $alice->get('/t/1');
check('opening it marks the new post', has($res, 'New since your last visit') && has($res, 'Welcome aboard, Alice!') && has($res, 'class="lf-badge lf-badge-owner">Owner<'));
check('and it is read the next time', !has($alice->get('/t/1'), 'New since your last visit') && !has($alice->get('/'), 'lf-tag lf-tag-new'));

echo "Moderating\n";
$res = $alice->post('/t/1/pin', ['csrf' => $csrfA], $origin);
check('a member can’t pin', flash($alice->follow($res)) === 'error: Only moderators can do that.' && (int) one($pdo, "SELECT pinned FROM threads WHERE id = 1") === 0);
check('members are kept out of the moderator pages', $alice->get('/reports')['status'] === 403 && $alice->get('/manage/members')['status'] === 403 && $alice->get('/manage/invites')['status'] === 403 && $alice->get('/manage')['status'] === 403);
$res = $owner->post('/t/1/pin', ['csrf' => $csrfO], $origin);
check('a moderator pins', flash($owner->follow($res)) === 'ok: Thread pinned.' && has($owner->get('/'), 'lf-thread is-pinned'));
$owner->post('/t/1/lock', ['csrf' => $csrfO], $origin);
$res = $alice->get('/t/1');
check('a locked thread takes no replies from members', has($res, 'This thread is locked') && !has($res, 'name="body"'));
$res = $alice->post('/t/1/reply', ['csrf' => $csrfA, 'body' => 'Can I still reply?'], $origin);
check('even if one is sent anyway', (int) one($pdo, "SELECT post_count FROM threads WHERE id = 1") === 2 && has($res, 'This thread is locked'));
$owner->post('/t/1/unlock', ['csrf' => $csrfO], $origin);
$res = $alice->post('/t/1/reply', ['csrf' => $csrfA, 'body' => str_repeat('x', 10001)], $origin);
check('a refused reply keeps what was typed', $res['status'] === 200 && has($res, 'That post is too long') && has($res, '>' . str_repeat('x', 50)));

echo "Reporting\n";
$res = $alice->post('/p/2/report', ['csrf' => $csrfA], $origin);
check('a member reports a post', flash($alice->follow($res)) === 'ok: Thanks. A moderator will take a look.');
$res = $owner->get('/reports');
check('moderators see the report, and a count in the bar', has($res, '1 report') && has($res, 'Welcome aboard, Alice!') && has($res, 'class="lf-count"') && !has($res, '>Alice<'));
$res = $owner->post('/reports/2/resolve', ['csrf' => $csrfO], $origin);
check('and can close it', flash($owner->follow($res)) === 'ok: Report closed.' && has($owner->get('/reports'), 'Nothing has been reported'));

echo "Pages of threads and posts\n";
$help = (string) one($pdo, "SELECT id FROM categories WHERE slug = 'help'");
$res = $alice->post('/t/1/reply', ['csrf' => $csrfA, 'body' => 'Second reply'], $origin);
check('a reply inside the first page goes back to it', to($res, '/t/1#post-3'));
$res = $alice->post('/t/1/reply', ['csrf' => $csrfA, 'body' => 'Third reply'], $origin);
check('one that starts a new page goes to that page', to($res, '/t/1?page=2#post-4'));
$res = $alice->get('/t/1');
check('long threads are split in pages', has($res, 'Page 1 of 2') && has($res, 'rel="next"') && !has($res, 'Third reply'));
check('the last page can be asked for', has($alice->get('/t/1?page=last'), 'Third reply') && has($alice->get('/t/1?page=2'), 'Page 2 of 2') && has($alice->get('/t/1?page=99'), 'Page 2 of 2'));
foreach (['Second thread', 'Third thread', 'Fourth thread'] as $name) {
    $alice->post('/new', ['csrf' => $csrfA, 'category' => $help, 'title' => $name, 'body' => 'Text'], $origin);
}
$res = $alice->get('/');
check('long lists are split in pages, pinned first', has($res, 'Page 1 of 2') && strpos($res['body'], 'Hello &lt;b&gt;world') < strpos($res['body'], 'Fourth thread') && has($res, '4 threads'));
check('categories have their own lists', has($alice->get('/c/help'), 'Stuck on something?') && has($alice->get('/c/show-your-site'), 'No threads here yet.'));

echo "Muting and deleting\n";
$res = $owner->post("/manage/members/$aliceId/mute", ['csrf' => $csrfO], $origin);
check('a moderator mutes a member', str_starts_with(flash($owner->follow($res)), 'ok: Member muted'));
$res = $alice->get('/t/1');
check('a muted member reads but can’t write', $res['status'] === 200 && has($res, 'You are muted') && !has($res, 'name="body"'));
$res = $alice->post('/new', ['csrf' => $csrfA, 'category' => $help, 'title' => 'Muted title', 'body' => 'Muted text'], $origin);
check('and can’t start threads', $res['status'] === 403 && has($res, 'You are muted, so you can read but not write.'));
$owner->post("/manage/members/$aliceId/unmute", ['csrf' => $csrfO], $origin);
$res = $alice->post('/p/4/delete', ['csrf' => $csrfA], $origin);
check('a member deletes their own reply', flash($alice->follow($res)) === 'ok: Post deleted.' && !has($alice->get('/t/1?page=last'), 'Third reply'));
$res = $alice->post('/p/2/delete', ['csrf' => $csrfA], $origin);
check('but not anyone else’s', flash($alice->follow($res)) === 'error: You can only delete your own posts.');
$res = $owner->post('/t/4/delete', ['csrf' => $csrfO], $origin);
check('a moderator deletes a thread', flash($owner->follow($res)) === 'ok: Thread deleted.' && $owner->get('/t/4')['status'] === 404);

echo "Managing\n";
check('moderators see the members', has($owner->get('/manage/members'), 'Alice') && has($owner->get('/manage/members'), 'Make moderator'));
$res = $alice->post("/manage/members/$aliceId/remove", ['csrf' => $csrfA], $origin);
check('a member can’t remove people', flash($alice->follow($res)) === 'error: Only the owner can do that.');
$ownerId = (int) one($pdo, "SELECT id FROM members WHERE username = 'Boss'");
check('nobody removes the owner', str_starts_with(flash($owner->follow($owner->post("/manage/members/$ownerId/remove", ['csrf' => $csrfO], $origin))), 'error:'));
$res = $owner->post('/manage/categories', ['csrf' => $csrfO, 'name' => 'Staff room', 'description' => 'Just us', 'staff_only' => '1'], $origin);
check('the owner adds a category', flash($owner->follow($res)) === 'ok: Category added.' && has($owner->get('/manage/categories'), 'value="Staff room"'));
check('staff-only categories are offered to staff only', has($owner->get('/new'), '>Staff room</option>') && !has($alice->get('/new'), '>Staff room</option>'));
$res = $owner->post('/manage/forum', ['csrf' => $csrfO, 'name' => 'Our Forum', 'rules' => "Be nice.\nNo spam."], $origin);
check('the owner writes the house rules', flash($owner->follow($res)) === 'ok: Saved.' && has($alice->get('/rules'), '<li>No spam.</li>'));

echo "Passwords and logging out\n";
$alice2 = new Browser($base);
$res = $alice2->post('/login', ['username' => 'alice', 'password' => 'alice password 1'], $origin);
check('logging in from another device', to($res, '/') && $alice2->get('/')['status'] === 200);
$res = $alice->post('/settings', ['csrf' => $csrfA, 'current' => 'wrong wrong wrong', 'new' => 'alice password 2', 'new2' => 'alice password 2'], $origin);
check('changing the password needs the current one', $res['status'] === 200 && has($res, 'That isn’t your current password.'));
$res = $alice->post('/settings', ['csrf' => $csrfA, 'current' => 'alice password 1', 'new' => 'alice password 2', 'new2' => 'alice password 2'], $origin);
check('changing it logs the other devices out', flash($alice->follow($res)) === 'ok: Password changed. Any other devices were logged out.' && to($alice2->get('/'), '/login') && $alice->get('/')['status'] === 200);
$res = $owner->post('/logout', [], $origin);
check('logging out needs the csrf value too', to($res, '/login') && $owner->get('/')['status'] === 200);
$res = $owner->post('/logout', ['csrf' => $csrfO], $origin);
check('logging out ends the login', to($res, '/login') && to($owner->get('/'), '/login') && to($owner->get('/manage/members'), '/login'));
check('a wrong password does not log in', ($r = $owner->post('/login', ['username' => 'boss', 'password' => 'nope nope nope'], $origin)) && $r['status'] === 200 && has($r, 'That username and password don’t match.'));
check('a login from another website is refused', ($r = $owner->post('/login', ['username' => 'boss', 'password' => 'correct horse battery'], ['Origin: https://evil.test'])) && has($r, 'That form had expired'));
for ($i = 0; $i < 8; $i++) {
    $owner->post('/login', ['username' => 'boss', 'password' => 'nope nope nope'], $origin);
}
check('repeated wrong passwords lock the address out for a while', has($owner->post('/login', ['username' => 'boss', 'password' => 'correct horse battery'], $origin), 'Too many wrong passwords'));
$pdo->exec('DELETE FROM login_failures');
check('the right password logs in again', to($owner->post('/login', ['username' => 'boss', 'password' => 'correct horse battery'], $origin), '/'));

echo "Coming in through a host app\n";
$hostSecret = 'e2e-host-secret-0123456789abcdef0123456789abcdef';
$hostUser = new Browser($base);
$entry = '/enter?t=' . lf_host_token_make(['ref' => 'e2e-1', 'acct' => 'e2e-A', 'acct_name' => 'E2E Co'], $hostSecret);
$res = $hostUser->get($entry);
check('an entry link starts a login that ends when the browser closes', to($res, '/') && preg_match('~^set-cookie: libreforum=[0-9a-f]{64}; path=/; HttpOnly; SameSite=Lax\s*$~im', $res['head']) === 1
    && !str_contains(strtolower($res['head']), 'expires=') && !str_contains(strtolower($res['head']), 'max-age='));
$res = $hostUser->get('/');
check('a member with no name yet is asked to choose one, with no password box', to($res, '/welcome') && ($welcome = $hostUser->get('/welcome')) && $welcome['status'] === 200 && has($welcome, 'name="username"') && !has($welcome, 'name="password"'));
$res = $hostUser->post('/welcome', ['csrf' => csrf($welcome), 'username' => 'HostMaya'], $origin);
check('and then they are in', to($res, '/') && flash($hostUser->follow($res)) === 'ok: Welcome, HostMaya!');
$res = $hostUser->get('/settings');
check('their settings say there is no password to change', has($res, 'You come in through another app') && !has($res, 'name="current"'));
$res = $guest->get($entry);
check('the same link does not work twice', $res['status'] === 410 && has($res, 'already used') && !isset($res['headers']['set-cookie']));
$res = $guest->get('/enter?t=' . substr($entry, strlen('/enter?t='), -3) . 'abc');
check('a forged link does not work at all, and neither does no link', $res['status'] === 410 && !isset($res['headers']['set-cookie']) && $guest->get('/enter')['status'] === 410);
$res = (new Browser($base))->post('/login', ['username' => 'hostmaya', 'password' => 'anything at all'], $origin);
check('they can’t log in on the forum’s own page', $res['status'] === 200 && has($res, 'That username and password don’t match.'));
$res = $hostUser->get('/', ['Sec-Fetch-Dest: iframe']);
check('a page shown inside a frame says so', has($res, '<body class="lf-embedded">') && !has($hostUser->get('/'), 'lf-embedded'));
$res = $guest->get('/login', ['X-Test-Frames: 1']);
check('frames are allowed only for the host’s own pages', str_contains($res['headers']['content-security-policy'], "frame-ancestors 'self' https://host.example") && !isset($res['headers']['x-frame-options'])
    && str_contains($guest->get('/login')['headers']['content-security-policy'], "frame-ancestors 'none'") && $guest->get('/login')['headers']['x-frame-options'] === 'DENY');
$res = $hostUser->post('/logout', ['csrf' => csrf($hostUser->get('/'))], $origin);
check('logging out works', to($res, '/login') && to($hostUser->get('/'), '/login'));
check('the second entry for the same person is the same member', to($hostUser->get('/enter?t=' . lf_host_token_make(['ref' => 'e2e-1', 'acct' => 'e2e-A'], $hostSecret)), '/') && (int) one($pdo, "SELECT COUNT(*) FROM members WHERE host_ref = 'e2e-1'") === 1
    && $hostUser->get('/')['status'] === 200);

echo "Odds and ends\n";
check('unknown pages are not found', $owner->get('/nope')['status'] === 404 && $owner->get('/t/9999')['status'] === 404 && $owner->get('/t/abc')['status'] === 404 && $owner->get('/c/nope')['status'] === 404 && $owner->get('/logout')['status'] === 404);
$res = $guest->get('/asset.php?f=theme.css&v=1');
check('the stylesheet is served, and cached for a year', $res['status'] === 200 && str_starts_with($res['headers']['content-type'], 'text/css') && str_contains($res['headers']['cache-control'], 'max-age=31536000') && has($res, '--lf-accent'));
check('so are the script and icon', $guest->get('/asset.php?f=forum.js')['status'] === 200 && $guest->get('/asset.php?f=icon.svg')['status'] === 200);
check('only real theme assets are served', $guest->get('/asset.php?f=..%2Fbootstrap.php')['status'] === 404 && $guest->get('/asset.php?f=theme.php')['status'] === 404 && $guest->get('/asset.php?f=nope.css')['status'] === 404 && $guest->get('/asset.php')['status'] === 404);
check('nothing in the database is an IP address', !str_contains(json_encode($pdo->query('SELECT * FROM login_failures')->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE) . json_encode($pdo->query('SELECT * FROM sessions')->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE), '127.0.0.1'));
$server_log = (string) file_get_contents($log);
check('the server logged no PHP errors or warnings', !preg_match('~(Warning|Notice|Fatal error|Deprecated|Uncaught)~', $server_log));

$bad = [];
foreach ($pages as [$what, $html]) {
    if (preg_match('~\sstyle\s*=~i', $html)) {
        $bad[] = "$what has an inline style";
    }
    if (preg_match('~\son[a-z]+\s*=~i', $html)) {
        $bad[] = "$what has an inline event handler";
    }
    if (preg_match('~<script(?![^>]*\ssrc=)~i', $html)) {
        $bad[] = "$what has an inline script";
    }
    if (preg_match('~(src|action)="https?://~i', $html) || preg_match('~<link[^>]+href="https?://~i', $html)) {
        $bad[] = "$what loads something from outside";
    }
    if (preg_match('~<a [^>]*href="https?://(?![^"]*127\.0\.0\.1)[^"]*"(?![^>]*noopener noreferrer)~i', $html)) {
        $bad[] = "$what has an outside link without noopener noreferrer";
    }
}
check('none of the ' . count($pages) . ' pages has inline styles or scripts, outside resources or unsafe links' . ($bad ? ': ' . implode('; ', array_slice($bad, 0, 3)) : ''), $bad === []);

echo "\n", $passed, ' passed, ', $failed, " failed\n";
if ($failed) {
    echo "\nServer log:\n", $server_log, "\n";
}
exit($failed ? 1 : 0);
