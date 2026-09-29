<?php
// The forum itself: categories, threads, posts, reading marks, moderation and reports. Functions that
// change things take the member doing it ($me) and check that they are allowed to. They return a
// message when something can't be done, and null (or a result) when it worked.

declare(strict_types=1);

require_once __DIR__ . '/members.php';
require_once __DIR__ . '/text.php';

// ---------- categories ----------

function lf_categories(PDO $pdo): array
{
    return $pdo->query(
        "SELECT c.*, (SELECT COUNT(*) FROM threads t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS thread_count
         FROM categories c ORDER BY c.position, c.id"
    )->fetchAll();
}

function lf_category_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = :s");
    $stmt->execute(['s' => $slug]);
    return $stmt->fetch() ?: null;
}

function lf_category_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :c");
    $stmt->execute(['c' => $id]);
    return $stmt->fetch() ?: null;
}

// "Help and questions" becomes "help-and-questions".
function lf_slugify(string $name): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $slug = strtolower(trim(preg_replace('~[^A-Za-z0-9]+~', '-', $ascii !== false ? $ascii : $name) ?? '', '-'));
    return substr($slug, 0, 40) ?: 'category';
}

// The owner adds a category. Returns [message, null] or [null, id].
function lf_category_add(PDO $pdo, array $me, string $name, string $description, bool $staffOnly): array
{
    if (!lf_is_owner($me)) {
        return ['Only the owner can do that.', null];
    }
    $name = lf_clean_line($name);
    $description = lf_clean_line($description);
    if ($name === '' || mb_strlen($name) > 60) {
        return ['Please give the category a name (up to 60 characters).', null];
    }
    if (mb_strlen($description) > 200) {
        return ['That description is too long (200 characters at most).', null];
    }
    $base = lf_slugify($name);
    $slug = $base;
    for ($n = 2; lf_category_by_slug($pdo, $slug) !== null; $n++) {
        $slug = substr($base, 0, 36) . '-' . $n;
    }
    $position = (int) $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM categories")->fetchColumn();
    $pdo->prepare("INSERT INTO categories (slug, name, description, position, staff_only, created_at) VALUES (:s, :n, :d, :p, :o, :t)")
        ->execute(['s' => $slug, 'n' => $name, 'd' => $description, 'p' => $position, 'o' => (int) $staffOnly, 't' => lf_now()]);
    return [null, (int) $pdo->lastInsertId()];
}

// Renames a category or changes who may start threads in it. Its address stays the same.
function lf_category_update(PDO $pdo, array $me, int $id, string $name, string $description, bool $staffOnly): ?string
{
    if (!lf_is_owner($me)) {
        return 'Only the owner can do that.';
    }
    $name = lf_clean_line($name);
    $description = lf_clean_line($description);
    if ($name === '' || mb_strlen($name) > 60) {
        return 'Please give the category a name (up to 60 characters).';
    }
    if (mb_strlen($description) > 200) {
        return 'That description is too long (200 characters at most).';
    }
    if (lf_category_get($pdo, $id) === null) {
        return 'That category doesn’t exist.';
    }
    $pdo->prepare("UPDATE categories SET name = :n, description = :d, staff_only = :o WHERE id = :c")
        ->execute(['n' => $name, 'd' => $description, 'o' => (int) $staffOnly, 'c' => $id]);
    return null;
}

// Moves a category up (-1) or down (1) in the list.
function lf_category_move(PDO $pdo, array $me, int $id, int $direction): ?string
{
    if (!lf_is_owner($me)) {
        return 'Only the owner can do that.';
    }
    $ids = array_map('intval', $pdo->query("SELECT id FROM categories ORDER BY position, id")->fetchAll(PDO::FETCH_COLUMN));
    $from = array_search($id, $ids, true);
    if ($from === false) {
        return 'That category doesn’t exist.';
    }
    $to = $from + ($direction < 0 ? -1 : 1);
    if ($to >= 0 && $to < count($ids)) {
        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
        $stmt = $pdo->prepare("UPDATE categories SET position = :p WHERE id = :c");
        foreach ($ids as $i => $categoryId) {
            $stmt->execute(['p' => $i + 1, 'c' => $categoryId]);
        }
    }
    return null;
}

// Deletes an empty category. Threads that were deleted earlier (and are only waiting to be purged) go with it.
function lf_category_delete(PDO $pdo, array $me, int $id): ?string
{
    if (!lf_is_owner($me)) {
        return 'Only the owner can do that.';
    }
    $category = lf_category_get($pdo, $id);
    if ($category === null) {
        return 'That category doesn’t exist.';
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE category_id = :c AND deleted_at IS NULL");
    $stmt->execute(['c' => $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        return 'Move or delete the threads in ' . $category['name'] . ' first.';
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() < 2) {
        return 'Keep at least one category.';
    }
    $pdo->prepare("DELETE FROM threads WHERE category_id = :c")->execute(['c' => $id]);
    $pdo->prepare("DELETE FROM categories WHERE id = :c")->execute(['c' => $id]);
    return null;
}

// ---------- reading ----------

// One page of threads for the whole forum or one category: pinned first, then by latest activity. Each
// thread says whether it is new to $me, or has replies they haven't read.
function lf_thread_list(PDO $pdo, array $me, ?int $categoryId, int $page, int $perPage): array
{
    $where = 't.deleted_at IS NULL' . ($categoryId !== null ? ' AND t.category_id = :c' : '');
    $params = $categoryId !== null ? ['c' => $categoryId] : [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads t WHERE $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare(
        "SELECT t.id, t.title, t.pinned, t.locked, t.post_count, t.last_post_id, t.last_post_at, t.created_at,
                c.slug AS category_slug, c.name AS category_name,
                a.username AS author, a.status AS author_status, a.role AS author_role,
                l.username AS last_author, l.status AS last_author_status,
                r.last_post_id AS read_post_id
         FROM threads t
         JOIN categories c ON c.id = t.category_id
         JOIN members a ON a.id = t.member_id
         LEFT JOIN posts lp ON lp.id = t.last_post_id
         LEFT JOIN members l ON l.id = lp.member_id
         LEFT JOIN thread_reads r ON r.thread_id = t.id AND r.member_id = :me
         WHERE $where
         ORDER BY t.pinned DESC, t.last_post_at DESC, t.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params + ['me' => $me['id']]);
    $threads = $stmt->fetchAll();
    foreach ($threads as &$thread) {
        // Never opened: new if it was started after the member joined. Opened: new if it has grown since.
        $thread['unread'] = $thread['read_post_id'] === null
            ? $thread['last_post_at'] > $me['created_at']
            : (int) $thread['read_post_id'] < (int) $thread['last_post_id'];
    }
    unset($thread);
    return ['threads' => $threads, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function lf_thread_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT t.*, c.slug AS category_slug, c.name AS category_name, c.staff_only AS category_staff_only,
                a.username AS author, a.status AS author_status, a.role AS author_role
         FROM threads t
         JOIN categories c ON c.id = t.category_id
         JOIN members a ON a.id = t.member_id
         WHERE t.id = :t AND t.deleted_at IS NULL"
    );
    $stmt->execute(['t' => $id]);
    return $stmt->fetch() ?: null;
}

// One page of a thread's posts, oldest first. 'first_id' is the post that starts the thread.
function lf_posts(PDO $pdo, int $threadId, int $page, int $perPage): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*), MIN(id) FROM posts WHERE thread_id = :t AND deleted_at IS NULL");
    $stmt->execute(['t' => $threadId]);
    [$total, $firstId] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare(
        "SELECT p.id, p.member_id, p.body, p.created_at, m.username, m.status AS member_status, m.role AS member_role
         FROM posts p JOIN members m ON m.id = p.member_id
         WHERE p.thread_id = :t AND p.deleted_at IS NULL
         ORDER BY p.id LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute(['t' => $threadId]);
    return ['posts' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'first_id' => $firstId];
}

// The thread a post belongs to, when both are still there.
function lf_post_thread_id(PDO $pdo, int $postId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT p.thread_id FROM posts p JOIN threads t ON t.id = p.thread_id
         WHERE p.id = :p AND p.deleted_at IS NULL AND t.deleted_at IS NULL"
    );
    $stmt->execute(['p' => $postId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

// Which page of its thread a post is on.
function lf_post_page(PDO $pdo, int $threadId, int $postId, int $perPage): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = :t AND deleted_at IS NULL AND id < :p");
    $stmt->execute(['t' => $threadId, 'p' => $postId]);
    return intdiv((int) $stmt->fetchColumn(), max(1, $perPage)) + 1;
}

// The last post of a thread a member has read, or null if they never opened it.
function lf_read_pointer(PDO $pdo, int $memberId, int $threadId): ?int
{
    $stmt = $pdo->prepare("SELECT last_post_id FROM thread_reads WHERE member_id = :m AND thread_id = :t");
    $stmt->execute(['m' => $memberId, 't' => $threadId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

function lf_mark_read(PDO $pdo, int $memberId, int $threadId, int $postId): void
{
    $pdo->prepare("INSERT INTO thread_reads (member_id, thread_id, last_post_id) VALUES (:m, :t, :p1) ON DUPLICATE KEY UPDATE last_post_id = GREATEST(last_post_id, :p2)")
        ->execute(['m' => $memberId, 't' => $threadId, 'p1' => $postId, 'p2' => $postId]);
}

// ---------- writing ----------

function lf_cant_write_message(?array $member): string
{
    if ($member !== null && $member['status'] === 'muted') {
        return 'You are muted, so you can read but not write.';
    }
    if ($member !== null && (string) $member['username'] === '') {
        return 'Please choose a username first.';
    }
    return 'You can’t write here right now.';
}

// Limits for ordinary members (staff have none). Returns a message when a limit has been reached.
function lf_rate_problem(PDO $pdo, array $me, bool $newThread): ?string
{
    if (lf_is_staff($me)) {
        return null;
    }
    $limits = (array) lf_cfg('limits', []);
    $perHour = (int) ($limits['posts_per_hour'] ?? 30);
    $perDay = (int) ($limits['threads_per_day'] ?? 10);
    $gap = (int) ($limits['seconds_between_posts'] ?? 5);
    // Deleted posts count too, so deleting can't be used to post faster.
    $stmt = $pdo->prepare("SELECT COUNT(*), MAX(created_at) FROM posts WHERE member_id = :m AND created_at > :t");
    $stmt->execute(['m' => $me['id'], 't' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    [$lastHour, $latest] = $stmt->fetch(PDO::FETCH_NUM);
    if ($latest !== null && time() - strtotime($latest . ' UTC') < $gap) {
        return 'Please wait a few seconds before posting again.';
    }
    if ((int) $lastHour >= $perHour) {
        return 'You have reached the limit of ' . $perHour . ' posts an hour. Please try again a little later.';
    }
    if ($newThread) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE member_id = :m AND created_at > :t");
        $stmt->execute(['m' => $me['id'], 't' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        if ((int) $stmt->fetchColumn() >= $perDay) {
            return 'You have started ' . $perDay . ' threads in the last day. Please try again later.';
        }
    }
    return null;
}

// Starts a thread. Returns [message, null] or [null, thread id].
function lf_thread_create(PDO $pdo, array $me, int $categoryId, string $title, string $body): array
{
    $category = lf_category_get($pdo, $categoryId);
    if ($category === null) {
        return ['Please choose a category.', null];
    }
    if (!lf_can_write($me)) {
        return [lf_cant_write_message($me), null];
    }
    if (!lf_can_start_thread($me, $category)) {
        return ['Only moderators can start threads in ' . $category['name'] . '.', null];
    }
    $title = lf_clean_title($title);
    $body = lf_clean_body($body);
    $problem = lf_title_problem($title) ?? lf_body_problem($body) ?? lf_rate_problem($pdo, $me, true);
    if ($problem !== null) {
        return [$problem, null];
    }
    $now = lf_now();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO threads (category_id, member_id, title, post_count, last_post_at, created_at) VALUES (:c, :m, :t, 1, :n1, :n2)")
            ->execute(['c' => $categoryId, 'm' => $me['id'], 't' => $title, 'n1' => $now, 'n2' => $now]);
        $threadId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO posts (thread_id, member_id, body, created_at) VALUES (:t, :m, :b, :n)")
            ->execute(['t' => $threadId, 'm' => $me['id'], 'b' => $body, 'n' => $now]);
        $postId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE threads SET last_post_id = :p WHERE id = :t")->execute(['p' => $postId, 't' => $threadId]);
        lf_mark_read($pdo, (int) $me['id'], $threadId, $postId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [null, $threadId];
}

// Adds a reply. Returns [message, null] or [null, post id].
function lf_reply_create(PDO $pdo, array $me, int $threadId, string $body): array
{
    if (!lf_can_write($me)) {
        return [lf_cant_write_message($me), null];
    }
    $body = lf_clean_body($body);
    $pdo->beginTransaction();
    try {
        // Locked so a moderator locking the thread at this moment is respected.
        $stmt = $pdo->prepare("SELECT id, locked FROM threads WHERE id = :t AND deleted_at IS NULL FOR UPDATE");
        $stmt->execute(['t' => $threadId]);
        $thread = $stmt->fetch();
        $problem = !$thread ? 'That thread doesn’t exist any more.'
            : (!lf_can_reply($me, $thread) ? 'This thread is locked.' : (lf_body_problem($body) ?? lf_rate_problem($pdo, $me, false)));
        if ($problem !== null) {
            $pdo->rollBack();
            return [$problem, null];
        }
        $now = lf_now();
        $pdo->prepare("INSERT INTO posts (thread_id, member_id, body, created_at) VALUES (:t, :m, :b, :n)")
            ->execute(['t' => $threadId, 'm' => $me['id'], 'b' => $body, 'n' => $now]);
        $postId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE threads SET post_count = post_count + 1, last_post_id = :p, last_post_at = :n WHERE id = :t")
            ->execute(['p' => $postId, 'n' => $now, 't' => $threadId]);
        lf_mark_read($pdo, (int) $me['id'], $threadId, $postId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [null, $postId];
}

// ---------- moderation ----------

// Pins or locks a thread, or undoes it. $field is 'pinned' or 'locked'.
function lf_thread_set(PDO $pdo, array $me, int $threadId, string $field, bool $value): ?string
{
    if (!lf_is_staff($me)) {
        return 'Only moderators can do that.';
    }
    if (!in_array($field, ['pinned', 'locked'], true)) {
        return 'Unknown action.';
    }
    if (lf_thread_get($pdo, $threadId) === null) {
        return 'That thread doesn’t exist any more.';
    }
    $pdo->prepare("UPDATE threads SET $field = :v WHERE id = :t")->execute(['v' => (int) $value, 't' => $threadId]);
    return null;
}

function lf_thread_move(PDO $pdo, array $me, int $threadId, int $categoryId): ?string
{
    if (!lf_is_staff($me)) {
        return 'Only moderators can do that.';
    }
    if (lf_thread_get($pdo, $threadId) === null) {
        return 'That thread doesn’t exist any more.';
    }
    if (lf_category_get($pdo, $categoryId) === null) {
        return 'Please choose a category.';
    }
    $pdo->prepare("UPDATE threads SET category_id = :c WHERE id = :t")->execute(['c' => $categoryId, 't' => $threadId]);
    return null;
}

// Marks reports on posts of a thread (or one post) as dealt with, because the post is gone.
function lf_reports_close(PDO $pdo, array $me, string $where, int $id): void
{
    $sql = $where === 'thread'
        ? "UPDATE reports SET handled_at = :n, handled_by = :m WHERE handled_at IS NULL AND post_id IN (SELECT id FROM posts WHERE thread_id = :i)"
        : "UPDATE reports SET handled_at = :n, handled_by = :m WHERE handled_at IS NULL AND post_id = :i";
    $pdo->prepare($sql)->execute(['n' => lf_now(), 'm' => $me['id'], 'i' => $id]);
}

// Deletes a thread. Staff can delete any. The person who started it can while nobody else has replied.
// (Deleted things stay in the database for 30 days, out of sight, then bin/maintain.php removes them.)
function lf_thread_delete(PDO $pdo, array $me, int $threadId): ?string
{
    $thread = lf_thread_get($pdo, $threadId);
    if ($thread === null) {
        return 'That thread doesn’t exist any more.';
    }
    if (!lf_is_staff($me)) {
        if ((int) $thread['member_id'] !== (int) $me['id']) {
            return 'You can only delete your own threads.';
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = :t AND deleted_at IS NULL AND member_id <> :m");
        $stmt->execute(['t' => $threadId, 'm' => $me['id']]);
        if ((int) $stmt->fetchColumn() > 0) {
            return 'Other members have replied, so a moderator has to remove this thread.';
        }
    }
    $pdo->prepare("UPDATE threads SET deleted_at = :n WHERE id = :t")->execute(['n' => lf_now(), 't' => $threadId]);
    lf_reports_close($pdo, $me, 'thread', $threadId);
    return null;
}

// Adds up a thread's posts again after one was deleted.
function lf_thread_recount(PDO $pdo, int $threadId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE thread_id = :t AND deleted_at IS NULL");
    $stmt->execute(['t' => $threadId]);
    $count = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, created_at FROM posts WHERE thread_id = :t AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute(['t' => $threadId]);
    $last = $stmt->fetch();
    $pdo->prepare("UPDATE threads SET post_count = :c, last_post_id = :p, last_post_at = COALESCE(:a, last_post_at) WHERE id = :t")
        ->execute(['c' => $count, 'p' => $last['id'] ?? null, 'a' => $last['created_at'] ?? null, 't' => $threadId]);
}

// Deletes one post. Staff can delete any post, members their own. Deleting the post that starts a thread
// deletes the thread. Returns [message, thread id, whether the whole thread went].
function lf_post_delete(PDO $pdo, array $me, int $postId): array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.thread_id, p.member_id, t.deleted_at AS thread_deleted
         FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.id = :p AND p.deleted_at IS NULL"
    );
    $stmt->execute(['p' => $postId]);
    $post = $stmt->fetch();
    if (!$post || $post['thread_deleted'] !== null) {
        return ['That post doesn’t exist any more.', null, false];
    }
    $threadId = (int) $post['thread_id'];
    if (!lf_is_staff($me) && (int) $post['member_id'] !== (int) $me['id']) {
        return ['You can only delete your own posts.', $threadId, false];
    }
    $stmt = $pdo->prepare("SELECT MIN(id) FROM posts WHERE thread_id = :t AND deleted_at IS NULL");
    $stmt->execute(['t' => $threadId]);
    if ((int) $stmt->fetchColumn() === (int) $post['id']) {
        $problem = lf_thread_delete($pdo, $me, $threadId);
        return [$problem, $threadId, $problem === null];
    }
    $pdo->prepare("UPDATE posts SET deleted_at = :n WHERE id = :p")->execute(['n' => lf_now(), 'p' => $postId]);
    lf_thread_recount($pdo, $threadId);
    lf_reports_close($pdo, $me, 'post', $postId);
    return [null, $threadId, false];
}

// ---------- reports ----------

// A member reports a post to the moderators. Reporting twice does nothing, and never says so.
function lf_report_add(PDO $pdo, array $me, int $postId): ?string
{
    if (!lf_can_write($me)) {
        return lf_cant_write_message($me);
    }
    $stmt = $pdo->prepare(
        "SELECT p.member_id FROM posts p JOIN threads t ON t.id = p.thread_id
         WHERE p.id = :p AND p.deleted_at IS NULL AND t.deleted_at IS NULL"
    );
    $stmt->execute(['p' => $postId]);
    $authorId = $stmt->fetchColumn();
    if ($authorId === false) {
        return 'That post doesn’t exist any more.';
    }
    if ((int) $authorId === (int) $me['id']) {
        return 'You can’t report your own post.';
    }
    $pdo->prepare("INSERT INTO reports (post_id, member_id, created_at) VALUES (:p, :m, :n1) ON DUPLICATE KEY UPDATE handled_at = NULL, handled_by = NULL, created_at = :n2")
        ->execute(['p' => $postId, 'm' => $me['id'], 'n1' => lf_now(), 'n2' => lf_now()]);
    return null;
}

// Posts with reports nobody has dealt with yet, oldest first. Who reported is never shown.
function lf_reports_open(PDO $pdo): array
{
    return $pdo->query(
        "SELECT p.id AS post_id, p.body, p.created_at AS post_at, t.id AS thread_id, t.title,
                m.id AS member_id, m.username, m.status AS member_status, m.role AS member_role,
                COUNT(r.id) AS reports, MIN(r.created_at) AS first_reported
         FROM reports r
         JOIN posts p ON p.id = r.post_id
         JOIN threads t ON t.id = p.thread_id
         JOIN members m ON m.id = p.member_id
         WHERE r.handled_at IS NULL AND p.deleted_at IS NULL AND t.deleted_at IS NULL
         GROUP BY p.id, t.id, t.title, m.id, m.username, m.status, m.role, p.body, p.created_at
         ORDER BY first_reported, p.id"
    )->fetchAll();
}

function lf_reports_open_count(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(DISTINCT r.post_id) FROM reports r
         JOIN posts p ON p.id = r.post_id JOIN threads t ON t.id = p.thread_id
         WHERE r.handled_at IS NULL AND p.deleted_at IS NULL AND t.deleted_at IS NULL"
    )->fetchColumn();
}

// A moderator decides a reported post is fine (or has dealt with it): the reports are closed.
function lf_report_resolve(PDO $pdo, array $me, int $postId): ?string
{
    if (!lf_is_staff($me)) {
        return 'Only moderators can do that.';
    }
    lf_reports_close($pdo, $me, 'post', $postId);
    return null;
}
