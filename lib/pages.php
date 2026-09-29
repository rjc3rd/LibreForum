<?php
// The pages. lf_dispatch() matches the address to a page function. Each one checks who is asking, does the
// work with the functions in forum.php and members.php, and then shows a template or sends the browser on.

declare(strict_types=1);

// ---------- helpers ----------

// A text field of a form or address. Anything that isn't plain text (someone sending body[]=x) becomes empty.
function lf_in(array $source, string $name): string
{
    $value = $source[$name] ?? '';
    return is_string($value) ? $value : '';
}

// The house rules, one per line.
function lf_rules_lines(string $rules): array
{
    return array_values(array_filter(array_map('trim', preg_split('~\n+~', $rules) ?: [])));
}

// A full web address inside the forum, for links people copy (invitations).
function lf_abs_url(string $path): string
{
    $root = rtrim((string) lf_cfg('url', ''), '/');
    if ($root === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $root = $host !== '' ? (lf_is_https() ? 'https' : 'http') . '://' . $host . lf_base() : lf_base();
    }
    return $root . '/' . ltrim($path, '/');
}

// Shows a template inside the theme's layout, with what every page needs.
function lf_show(array $req, string $template, array $vars = [], int $status = 200): void
{
    $me = $req['me'];
    $pdo = $req['pdo'];
    $forum = lf_forum_name($pdo);
    lf_page($template, $vars + [
        'me' => $me,
        'path' => $req['path'],
        'csrf' => $me['csrf'] ?? '',
        'forum' => $forum,
        'staff' => lf_is_staff($me),
        'owner' => lf_is_owner($me),
        'canWrite' => lf_can_write($me),
        'reportsOpen' => lf_is_staff($me) ? lf_reports_open_count($pdo) : 0,
        'flash' => $me !== null ? lf_flash_take($pdo, $me) : null,
        'sourceUrl' => (string) lf_cfg('source_url', 'https://github.com/rjc3rd/LibreForum'),
        'version' => LF_VERSION,
        'embedded' => lf_is_embedded(),
        'title' => $forum,
    ], $status);
}

function lf_title(array $req, string $page): string
{
    return $page . ' · ' . lf_forum_name($req['pdo']);
}

function lf_error(array $req, int $status, string $message = ''): void
{
    $headings = [403 => 'Not allowed', 404 => 'Page not found', 410 => 'Link expired'];
    $heading = $headings[$status] ?? 'Something went wrong';
    lf_show($req, 'error', ['title' => lf_title($req, $heading), 'heading' => $heading, 'message' => $message !== '' ? $message : 'There is nothing at this address.', 'status' => $status], $status);
}

// Who is asking. Visitors who aren't logged in go to the login page (or to setup, on a brand new forum),
// and members who haven't chosen a username yet go to the welcome page.
function lf_need_login(array $req, bool $allowNameless = false): array
{
    $me = $req['me'];
    if ($me === null) {
        lf_redirect(lf_owner_exists($req['pdo']) ? 'login' : 'setup');
    }
    if ((string) $me['username'] === '' && !$allowNameless) {
        lf_redirect('welcome');
    }
    return $me;
}

// A form posted by a logged-in member has to come from this site and carry the login's csrf value.
// If it doesn't, says so and sends the member to $back.
function lf_form_ok(array $req, string $back): void
{
    if (!lf_origin_ok($_SERVER) || !lf_csrf_ok($req['me'], $req['post'])) {
        lf_flash_set($req['pdo'], $req['me'], 'error', 'That form had expired. Please try again.');
        lf_redirect($back);
    }
}

// Leaves a message for the next page (the problem, or $done when there was none) and goes to $to.
function lf_finish(array $req, ?string $problem, string $done, string $to): never
{
    lf_flash_set($req['pdo'], $req['me'], $problem === null ? 'ok' : 'error', $problem ?? $done);
    lf_redirect($to);
}

// Starts a login for a member: sets the cookie and leaves a message for the first page.
function lf_log_in(array $req, int $memberId, string $welcome): void
{
    if ($req['token'] !== '') {
        lf_session_end($req['pdo'], $req['token']);   // whatever login this browser had before
    }
    $token = lf_session_start($req['pdo'], $memberId);
    lf_flash_set($req['pdo'], ['session_key' => strtoupper(bin2hex(lf_token_hash($token)))], 'ok', $welcome);
    lf_cookie_set($token);
}

// ---------- routing ----------

function lf_routes(): array
{
    return [
        ['GET', '~^/$~D', 'lf_pg_home'],
        ['GET', '~^/c/([a-z0-9-]{1,40})$~D', 'lf_pg_category'],
        ['GET', '~^/t/(\d{1,10})$~D', 'lf_pg_thread'],
        ['POST', '~^/t/(\d{1,10})/reply$~D', 'lf_pg_reply'],
        ['POST', '~^/t/(\d{1,10})/(pin|unpin|lock|unlock)$~D', 'lf_pg_thread_flag'],
        ['POST', '~^/t/(\d{1,10})/move$~D', 'lf_pg_thread_move'],
        ['POST', '~^/t/(\d{1,10})/delete$~D', 'lf_pg_thread_delete'],
        ['POST', '~^/p/(\d{1,10})/delete$~D', 'lf_pg_post_delete'],
        ['POST', '~^/p/(\d{1,10})/report$~D', 'lf_pg_post_report'],
        ['GET', '~^/new$~D', 'lf_pg_new'],
        ['POST', '~^/new$~D', 'lf_pg_new_submit'],
        ['GET', '~^/rules$~D', 'lf_pg_rules'],
        ['GET', '~^/settings$~D', 'lf_pg_settings'],
        ['POST', '~^/settings$~D', 'lf_pg_settings_submit'],
        ['GET', '~^/reports$~D', 'lf_pg_reports'],
        ['POST', '~^/reports/(\d{1,10})/resolve$~D', 'lf_pg_report_resolve'],
        ['GET', '~^/manage$~D', 'lf_pg_manage_index'],
        ['GET', '~^/manage/(members|invites|categories|forum)$~D', 'lf_pg_manage'],
        ['POST', '~^/manage/members/(\d{1,10})/(mute|unmute|moderator|member|remove)$~D', 'lf_pg_member_action'],
        ['POST', '~^/manage/invites$~D', 'lf_pg_invite_create'],
        ['POST', '~^/manage/invites/(\d{1,10})/revoke$~D', 'lf_pg_invite_revoke'],
        ['POST', '~^/manage/categories$~D', 'lf_pg_category_add'],
        ['POST', '~^/manage/categories/(\d{1,10})/(save|up|down|delete)$~D', 'lf_pg_category_action'],
        ['POST', '~^/manage/forum$~D', 'lf_pg_forum_save'],
        ['GET', '~^/enter$~D', 'lf_pg_enter'],
        ['GET', '~^/login$~D', 'lf_pg_login'],
        ['POST', '~^/login$~D', 'lf_pg_login_submit'],
        ['POST', '~^/logout$~D', 'lf_pg_logout'],
        ['GET', '~^/invite/([0-9a-f]{32})$~D', 'lf_pg_invite'],
        ['POST', '~^/invite/([0-9a-f]{32})$~D', 'lf_pg_invite_submit'],
        ['GET', '~^/setup$~D', 'lf_pg_setup'],
        ['POST', '~^/setup$~D', 'lf_pg_setup_submit'],
        ['GET', '~^/welcome$~D', 'lf_pg_welcome'],
        ['POST', '~^/welcome$~D', 'lf_pg_welcome_submit'],
    ];
}

function lf_dispatch(PDO $pdo, string $method, string $path): void
{
    $token = (string) ($_COOKIE[LF_COOKIE] ?? '');
    $me = $token !== '' ? lf_session_lookup($pdo, $token) : null;
    if ($token !== '' && $me === null) {
        lf_cookie_clear();   // a login that no longer exists
    }
    $req = [
        'pdo' => $pdo, 'me' => $me, 'token' => $token, 'path' => $path,
        'method' => $method === 'HEAD' ? 'GET' : $method, 'get' => $_GET, 'post' => $_POST,
        'ip' => lf_client_ip($_SERVER, (array) lf_cfg('trusted_proxies', [])),
    ];
    foreach (lf_routes() as [$verb, $pattern, $handler]) {
        if ($verb === $req['method'] && preg_match($pattern, $path, $found)) {
            $handler($req, ...array_slice($found, 1));
            return;
        }
    }
    lf_error($req, 404);
}

// ---------- reading ----------

function lf_pg_home(array $req): void
{
    lf_pg_list($req, null);
}

function lf_pg_category(array $req, string $slug): void
{
    lf_pg_list($req, $slug);
}

function lf_pg_list(array $req, ?string $slug): void
{
    $me = lf_need_login($req);
    $pdo = $req['pdo'];
    $category = null;
    if ($slug !== null) {
        $category = lf_category_by_slug($pdo, $slug);
        if ($category === null) {
            lf_error($req, 404, 'There is no such category.');
            return;
        }
    }
    $result = lf_thread_list($pdo, $me, $category !== null ? (int) $category['id'] : null, max(1, (int) lf_in($req['get'], 'page')), (int) lf_cfg('threads_per_page', 25));
    lf_show($req, 'list', [
        'title' => lf_title($req, $category['name'] ?? 'Latest threads'),
        'category' => $category, 'categories' => lf_categories($pdo),
        'threads' => $result['threads'], 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages'],
        'pagerBase' => lf_url($category !== null ? 'c/' . $category['slug'] : ''),
        'canStart' => lf_can_write($me),
    ]);
}

// A thread with its posts, the reply box and (for staff) the moderator tools. $compose is what was typed
// in the reply box when a reply was refused, so it can be shown again.
function lf_pg_thread(array $req, string $id, array $compose = []): void
{
    $me = lf_need_login($req);
    $pdo = $req['pdo'];
    $thread = lf_thread_get($pdo, (int) $id);
    if ($thread === null) {
        lf_error($req, 404, 'That thread doesn’t exist, or it was deleted.');
        return;
    }
    $want = lf_in($req['get'], 'page');
    $result = lf_posts($pdo, (int) $thread['id'], $want === 'last' ? PHP_INT_MAX : max(1, (int) $want), (int) lf_cfg('posts_per_page', 25));
    // What the member had read before this visit, so new posts can be marked. Then everything shown counts as read.
    $readBefore = lf_read_pointer($pdo, (int) $me['id'], (int) $thread['id']);
    $firstNew = null;
    if ($readBefore !== null) {
        foreach ($result['posts'] as $post) {
            if ((int) $post['id'] > $readBefore) {
                $firstNew = (int) $post['id'];
                break;
            }
        }
    }
    if ($result['posts']) {
        lf_mark_read($pdo, (int) $me['id'], (int) $thread['id'], (int) end($result['posts'])['id']);
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = :t AND deleted_at IS NULL AND member_id <> :m");
    $stmt->execute(['t' => $thread['id'], 'm' => $thread['member_id']]);
    lf_show($req, 'thread', [
        'title' => lf_title($req, $thread['title']),
        'thread' => $thread, 'posts' => $result['posts'], 'page' => $result['page'], 'pages' => $result['pages'],
        'firstId' => $result['first_id'], 'firstNew' => $firstNew, 'readBefore' => $readBefore,
        'pagerBase' => lf_url('t/' . $thread['id']),
        'canReply' => lf_can_reply($me, $thread),
        'compose' => $compose, 'categories' => lf_categories($pdo),
        'othersReplied' => (int) $stmt->fetchColumn() > 0,
    ]);
}

function lf_pg_rules(array $req): void
{
    lf_need_login($req);
    lf_show($req, 'rules', ['title' => lf_title($req, 'House rules'), 'rules' => lf_rules_lines(lf_rules($req['pdo']))]);
}

// ---------- writing ----------

function lf_pg_new(array $req, array $form = []): void
{
    $me = lf_need_login($req);
    $pdo = $req['pdo'];
    if (!lf_can_write($me)) {
        lf_error($req, 403, lf_cant_write_message($me));
        return;
    }
    $categories = array_values(array_filter(lf_categories($pdo), fn ($c) => lf_can_start_thread($me, $c)));
    $selected = isset($form['category']) ? (int) $form['category'] : 0;
    if ($selected === 0 && lf_in($req['get'], 'c') !== '') {
        $selected = (int) (lf_category_by_slug($pdo, lf_in($req['get'], 'c'))['id'] ?? 0);
    }
    lf_show($req, 'new', [
        'title' => lf_title($req, 'New thread'), 'categories' => $categories, 'selected' => $selected,
        'values' => $form + ['title' => '', 'body' => ''], 'error' => $form['error'] ?? null,
    ]);
}

function lf_pg_new_submit(array $req): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'new');
    [$problem, $id] = lf_thread_create($req['pdo'], $me, (int) lf_in($req['post'], 'category'), lf_in($req['post'], 'title'), lf_in($req['post'], 'body'));
    if ($problem !== null) {
        lf_pg_new($req, ['error' => $problem, 'category' => lf_in($req['post'], 'category'), 'title' => lf_in($req['post'], 'title'), 'body' => lf_in($req['post'], 'body')]);
        return;
    }
    lf_finish($req, null, 'Thread posted.', 't/' . $id);
}

function lf_pg_reply(array $req, string $id): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, "t/$id");
    $pdo = $req['pdo'];
    [$problem, $postId] = lf_reply_create($pdo, $me, (int) $id, lf_in($req['post'], 'body'));
    if ($problem !== null) {
        lf_pg_thread($req, $id, ['error' => $problem, 'body' => lf_in($req['post'], 'body')]);
        return;
    }
    // Back to the new reply, which is at the end of the thread.
    $pages = max(1, (int) ceil((int) lf_thread_get($pdo, (int) $id)['post_count'] / (int) lf_cfg('posts_per_page', 25)));
    lf_flash_set($pdo, $me, 'ok', 'Reply posted.');
    lf_redirect("t/$id" . ($pages > 1 ? "?page=$pages" : '') . '#post-' . $postId);
}

function lf_pg_thread_flag(array $req, string $id, string $action): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, "t/$id");
    [$field, $value, $done] = [
        'pin' => ['pinned', true, 'Thread pinned.'], 'unpin' => ['pinned', false, 'Thread unpinned.'],
        'lock' => ['locked', true, 'Thread locked. Only moderators can reply now.'], 'unlock' => ['locked', false, 'Thread unlocked.'],
    ][$action];
    lf_finish($req, lf_thread_set($req['pdo'], $me, (int) $id, $field, $value), $done, "t/$id");
}

function lf_pg_thread_move(array $req, string $id): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, "t/$id");
    lf_finish($req, lf_thread_move($req['pdo'], $me, (int) $id, (int) lf_in($req['post'], 'category')), 'Thread moved.', "t/$id");
}

function lf_pg_thread_delete(array $req, string $id): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, "t/$id");
    $problem = lf_thread_delete($req['pdo'], $me, (int) $id);
    lf_finish($req, $problem, 'Thread deleted.', $problem === null ? '' : "t/$id");
}

// Where a moderation form on the reports page sends the browser back to.
function lf_back(array $req, string $fallback): string
{
    return lf_in($req['post'], 'back') === 'reports' ? 'reports' : $fallback;
}

function lf_pg_post_delete(array $req, string $id): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, lf_back($req, ''));
    [$problem, $threadId, $gone] = lf_post_delete($req['pdo'], $me, (int) $id);
    $back = lf_back($req, $threadId === null || $gone ? '' : "t/$threadId");
    lf_finish($req, $problem, $gone ? 'Thread deleted.' : 'Post deleted.', $back);
}

function lf_pg_post_report(array $req, string $id): void
{
    $me = lf_need_login($req);
    $threadId = lf_post_thread_id($req['pdo'], (int) $id);
    lf_form_ok($req, $threadId !== null ? "t/$threadId" : '');
    lf_finish($req, lf_report_add($req['pdo'], $me, (int) $id), 'Thanks. A moderator will take a look.', $threadId !== null ? "t/$threadId" : '');
}

function lf_pg_settings(array $req, array $form = []): void
{
    $me = lf_need_login($req);
    lf_show($req, 'settings', ['title' => lf_title($req, 'Your settings'), 'error' => $form['error'] ?? null]);
}

function lf_pg_settings_submit(array $req): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'settings');
    $problem = lf_password_change($req['pdo'], (int) $me['id'], lf_in($req['post'], 'current'), lf_in($req['post'], 'new'), lf_in($req['post'], 'new2'), $req['token']);
    if ($problem !== null) {
        lf_pg_settings($req, ['error' => $problem]);
        return;
    }
    lf_finish($req, null, 'Password changed. Any other devices were logged out.', 'settings');
}

// ---------- moderators ----------

function lf_pg_reports(array $req): void
{
    $me = lf_need_login($req);
    if (!lf_is_staff($me)) {
        lf_error($req, 403, 'Only moderators can see reports.');
        return;
    }
    $pdo = $req['pdo'];
    $perPage = (int) lf_cfg('posts_per_page', 25);
    $reports = lf_reports_open($pdo);
    foreach ($reports as &$report) {
        $page = lf_post_page($pdo, (int) $report['thread_id'], (int) $report['post_id'], $perPage);
        $report['link'] = lf_url('t/' . $report['thread_id']) . ($page > 1 ? '?page=' . $page : '') . '#post-' . $report['post_id'];
    }
    unset($report);
    lf_show($req, 'reports', ['title' => lf_title($req, 'Reports'), 'reports' => $reports]);
}

function lf_pg_report_resolve(array $req, string $id): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'reports');
    lf_finish($req, lf_report_resolve($req['pdo'], $me, (int) $id), 'Report closed.', 'reports');
}

// ---------- managing ----------

function lf_pg_manage_index(array $req): void
{
    $me = lf_need_login($req);
    if (!lf_is_staff($me)) {
        lf_error($req, 403, 'Only moderators can manage the forum.');
        return;
    }
    lf_redirect('manage/members');
}

// The four management pages: people (moderators too), then the owner's invitations, categories and forum settings.
function lf_pg_manage(array $req, string $section, array $extra = []): void
{
    $me = lf_need_login($req);
    if (!lf_is_staff($me) || ($section !== 'members' && !lf_is_owner($me))) {
        lf_error($req, 403, $section === 'members' ? 'Only moderators can manage the forum.' : 'Only the owner can do that.');
        return;
    }
    $pdo = $req['pdo'];
    $names = ['members' => 'Members', 'invites' => 'Invitations', 'categories' => 'Categories', 'forum' => 'Forum'];
    $vars = ['section' => $section, 'tabs' => lf_is_owner($me) ? $names : ['members' => 'Members'], 'title' => lf_title($req, 'Manage · ' . $names[$section])];
    if ($section === 'members') {
        $vars['members'] = lf_members_list($pdo);
    } elseif ($section === 'invites') {
        $vars['invites'] = lf_invites_open($pdo, (int) $me['account_id']);
        $vars['seats'] = lf_account_seats($pdo, (int) $me['account_id']);
        $vars['newLink'] = $extra['link'] ?? null;
    } elseif ($section === 'categories') {
        $vars['categories'] = lf_categories($pdo);
    } else {
        $vars['forumName'] = lf_forum_name($pdo);
        $vars['rulesText'] = lf_rules($pdo);
    }
    lf_show($req, 'manage', $vars + $extra);
}

function lf_pg_member_action(array $req, string $id, string $action): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'manage/members');
    $pdo = $req['pdo'];
    $target = (int) $id;
    $problem = match ($action) {
        'mute' => lf_member_mute($pdo, $me, $target, true),
        'unmute' => lf_member_mute($pdo, $me, $target, false),
        'moderator' => lf_member_set_role($pdo, $me, $target, 'moderator'),
        'member' => lf_member_set_role($pdo, $me, $target, 'member'),
        'remove' => lf_member_remove($pdo, $me, $target),
    };
    $done = ['mute' => 'Member muted. They can still read, but not write.', 'unmute' => 'Member can write again.',
        'moderator' => 'Now a moderator.', 'member' => 'Now a member.', 'remove' => 'Member removed. What they wrote stays, as “Former member”.'][$action];
    lf_finish($req, $problem, $done, lf_back($req, 'manage/members'));
}

function lf_pg_invite_create(array $req): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'manage/invites');
    [$problem, $token] = lf_invite_create($req['pdo'], $me, lf_in($req['post'], 'role'), (int) lf_in($req['post'], 'days'));
    if ($problem !== null) {
        lf_finish($req, $problem, '', 'manage/invites');
    }
    // Only a hash is kept, so this is the one time the link can be shown.
    lf_pg_manage($req, 'invites', ['link' => lf_abs_url('invite/' . $token)]);
}

function lf_pg_invite_revoke(array $req, string $id): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'manage/invites');
    lf_finish($req, lf_invite_revoke($req['pdo'], $me, (int) $id), 'Invitation cancelled.', 'manage/invites');
}

function lf_pg_category_add(array $req): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'manage/categories');
    [$problem] = lf_category_add($req['pdo'], $me, lf_in($req['post'], 'name'), lf_in($req['post'], 'description'), lf_in($req['post'], 'staff_only') !== '');
    lf_finish($req, $problem, 'Category added.', 'manage/categories');
}

function lf_pg_category_action(array $req, string $id, string $action): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'manage/categories');
    $pdo = $req['pdo'];
    $category = (int) $id;
    $problem = match ($action) {
        'save' => lf_category_update($pdo, $me, $category, lf_in($req['post'], 'name'), lf_in($req['post'], 'description'), lf_in($req['post'], 'staff_only') !== ''),
        'up' => lf_category_move($pdo, $me, $category, -1),
        'down' => lf_category_move($pdo, $me, $category, 1),
        'delete' => lf_category_delete($pdo, $me, $category),
    };
    lf_finish($req, $problem, ['save' => 'Category saved.', 'up' => 'Moved up.', 'down' => 'Moved down.', 'delete' => 'Category deleted.'][$action], 'manage/categories');
}

function lf_pg_forum_save(array $req): void
{
    $me = lf_need_login($req);
    lf_form_ok($req, 'manage/forum');
    if (!lf_is_owner($me)) {
        lf_finish($req, 'Only the owner can do that.', '', 'manage/forum');
    }
    $name = lf_clean_line(lf_in($req['post'], 'name'));
    $rules = lf_clean_body(lf_in($req['post'], 'rules'));
    $problem = $name === '' || mb_strlen($name) > 60 ? 'Please name your forum (up to 60 characters).'
        : (mb_strlen($rules) > 2000 ? 'The rules are too long (2,000 characters at most).' : null);
    if ($problem === null) {
        lf_setting_set($req['pdo'], 'forum_name', $name);
        // An empty box means "use the default rules again" (the theme's, or the built-in ones).
        $rules === '' ? lf_setting_delete($req['pdo'], 'rules') : lf_setting_set($req['pdo'], 'rules', $rules);
    }
    lf_finish($req, $problem, 'Saved.', 'manage/forum');
}

// ---------- logging in, joining, setting up ----------

function lf_pg_login(array $req, array $form = []): void
{
    if ($req['me'] !== null) {
        lf_redirect('');
    }
    if (!lf_owner_exists($req['pdo'])) {
        lf_redirect('setup');
    }
    lf_show($req, 'login', ['title' => lf_title($req, 'Log in'), 'bare' => true, 'error' => $form['error'] ?? null, 'username' => $form['username'] ?? '']);
}

function lf_pg_login_submit(array $req): void
{
    if (!lf_origin_ok($_SERVER)) {
        lf_pg_login($req, ['error' => 'That form had expired. Please try again.']);
        return;
    }
    $username = lf_in($req['post'], 'username');
    [$error, $token] = lf_login($req['pdo'], $username, lf_in($req['post'], 'password'), $req['ip']);
    if ($error !== null) {
        lf_pg_login($req, ['error' => $error, 'username' => $username]);
        return;
    }
    if ($req['token'] !== '') {
        lf_session_end($req['pdo'], $req['token']);
    }
    lf_cookie_set($token);
    lf_redirect('');
}

function lf_pg_logout(array $req): void
{
    if ($req['me'] === null) {
        lf_redirect('login');
    }
    if (lf_origin_ok($_SERVER) && lf_csrf_ok($req['me'], $req['post'])) {
        lf_session_end($req['pdo'], $req['token']);
        lf_cookie_clear();
    }
    lf_redirect('login');
}

// The way in for people whose host app runs the forum: a signed, single-use link (see lib/host.php).
function lf_pg_enter(array $req): void
{
    $secret = (string) lf_cfg('host.secret', '');
    if ($secret === '') {
        lf_error($req, 404);
        return;
    }
    [$claims, $why] = lf_host_token_verify(lf_in($req['get'], 't'), $secret);
    if ($claims === null) {
        error_log("LibreForum: an entry link was refused ($why)");
        lf_error($req, 410, 'This link has expired or was already used. Please open the forum again from your account.');
        return;
    }
    [$problem, $memberId] = lf_host_enter($req['pdo'], $claims);
    if ($problem !== null) {
        lf_error($req, $problem === LF_HOST_LINK_USED ? 410 : 403, $problem);
        return;
    }
    if ($req['token'] !== '') {
        lf_session_end($req['pdo'], $req['token']);   // whatever login this browser had before
    }
    lf_cookie_set(lf_session_start($req['pdo'], (int) $memberId, true), true);
    lf_redirect(ltrim((string) ($claims['next'] ?? ''), '/'));
}

// Where somebody who follows an invitation link chooses their username and password.
function lf_pg_invite(array $req, string $token, array $form = []): void
{
    $pdo = $req['pdo'];
    if (lf_invite_find($pdo, $token) === null) {
        lf_error($req, 410, 'This invitation link has expired or was already used. Please ask for a new one.');
        return;
    }
    lf_show($req, 'welcome', [
        'title' => lf_title($req, 'Welcome'), 'bare' => true, 'mode' => 'invite', 'action' => lf_url('invite/' . $token),
        'error' => $form['error'] ?? null, 'username' => $form['username'] ?? '', 'rules' => lf_rules_lines(lf_rules($pdo)),
    ]);
}

function lf_pg_invite_submit(array $req, string $token): void
{
    if (!lf_origin_ok($_SERVER)) {
        lf_pg_invite($req, $token, ['error' => 'That form had expired. Please try again.']);
        return;
    }
    $username = lf_in($req['post'], 'username');
    [$problem, $memberId] = lf_invite_accept($req['pdo'], $token, $username, lf_in($req['post'], 'password'), lf_in($req['post'], 'password2'));
    if ($problem !== null) {
        lf_pg_invite($req, $token, ['error' => $problem, 'username' => $username]);
        return;
    }
    lf_log_in($req, (int) $memberId, 'Welcome, ' . trim($username) . '!');
    lf_redirect('');
}

// Somebody who has no username yet (someone else's app signed them in, or the owner made them without one) picks it here.
function lf_pg_welcome(array $req, array $form = []): void
{
    $me = lf_need_login($req, true);
    if ((string) $me['username'] !== '') {
        lf_redirect('');
    }
    lf_show($req, 'welcome', [
        'title' => lf_title($req, 'Welcome'), 'bare' => true, 'mode' => 'name', 'action' => lf_url('welcome'),
        'error' => $form['error'] ?? null, 'username' => $form['username'] ?? '', 'rules' => lf_rules_lines(lf_rules($req['pdo'])),
    ]);
}

function lf_pg_welcome_submit(array $req): void
{
    $me = lf_need_login($req, true);
    lf_form_ok($req, 'welcome');
    $username = lf_in($req['post'], 'username');
    $problem = lf_member_set_username($req['pdo'], (int) $me['id'], $username);
    if ($problem !== null) {
        lf_pg_welcome($req, ['error' => $problem, 'username' => $username]);
        return;
    }
    lf_finish($req, null, 'Welcome, ' . trim($username) . '!', '');
}

// First run: whoever gets here first creates the forum and its owner.
function lf_pg_setup(array $req, array $form = []): void
{
    if (lf_owner_exists($req['pdo'])) {
        lf_redirect($req['me'] !== null ? '' : 'login');
    }
    lf_show($req, 'setup', ['title' => 'Set up your forum', 'bare' => true, 'error' => $form['error'] ?? null, 'values' => $form + ['forum' => '', 'username' => '']]);
}

function lf_pg_setup_submit(array $req): void
{
    if (lf_owner_exists($req['pdo'])) {
        lf_redirect('login');
    }
    $form = ['forum' => lf_in($req['post'], 'forum'), 'username' => lf_in($req['post'], 'username')];
    if (!lf_origin_ok($_SERVER)) {
        lf_pg_setup($req, $form + ['error' => 'That form had expired. Please try again.']);
        return;
    }
    [$problem, $memberId] = lf_setup_owner($req['pdo'], $form['forum'], $form['username'], lf_in($req['post'], 'password'), lf_in($req['post'], 'password2'));
    if ($problem !== null) {
        lf_pg_setup($req, $form + ['error' => $problem]);
        return;
    }
    lf_log_in($req, (int) $memberId, 'Your forum is ready. Next: invite some people.');
    lf_redirect('');
}
