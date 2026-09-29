<?php
// Installing: the tables, the owner and the first categories.

declare(strict_types=1);

require_once __DIR__ . '/members.php';
require_once __DIR__ . '/text.php';

// The statements in sql/schema.sql, without the comments.
function lf_schema_statements(): array
{
    $sql = preg_replace('~--[^\n]*~', '', (string) file_get_contents(LF_ROOT . '/sql/schema.sql')) ?? '';
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

// Creates the tables (ones that already exist are left as they are).
function lf_install_schema(PDO $pdo): void
{
    foreach (lf_schema_statements() as $statement) {
        $pdo->exec($statement);
    }
}

// [slug, name, description, only staff can start threads]
const LF_DEFAULT_CATEGORIES = [
    ['announcements', 'Announcements', 'News and updates from the people who run this place.', true],
    ['help', 'Help and questions', 'Stuck on something? Ask here.', false],
    ['show-your-site', 'Show your site', 'Share something you made.', false],
];

// Adds the first categories, unless there are some already.
function lf_seed_categories(PDO $pdo): void
{
    if ($pdo->query("SELECT 1 FROM categories LIMIT 1")->fetchColumn()) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO categories (slug, name, description, position, staff_only, created_at) VALUES (:s, :n, :d, :p, :o, :t)");
    foreach (LF_DEFAULT_CATEGORIES as $i => [$slug, $name, $description, $staffOnly]) {
        $stmt->execute(['s' => $slug, 'n' => $name, 'd' => $description, 'p' => $i + 1, 'o' => (int) $staffOnly, 't' => lf_now()]);
    }
}

// First run: creates the forum's account, its owner and the first categories. It works once. Returns
// [message, null] or [null, member id].
function lf_setup_owner(PDO $pdo, string $forumName, string $username, string $password, string $confirm): array
{
    $forumName = trim(preg_replace('~\s+~u', ' ', lf_clean_text($forumName)) ?? '');
    $username = trim($username);
    if ($forumName === '' || mb_strlen($forumName) > 60) {
        return ['Please name your forum (up to 60 characters).', null];
    }
    $problem = lf_username_problem($pdo, $username, true) ?? lf_password_problem($password, $confirm);
    if ($problem !== null) {
        return [$problem, null];
    }
    $done = 'This forum has already been set up.';
    if (lf_owner_exists($pdo)) {
        return [$done, null];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        // A second visitor arriving at the same moment hits this unique key and stops here.
        $pdo->prepare("INSERT INTO settings (name, value) VALUES ('owner_created', :t)")->execute(['t' => lf_now()]);
        $accountId = lf_account_create($pdo, $forumName);
        $memberId = lf_member_create($pdo, $accountId, $username, $hash, 'owner');
        lf_setting_set($pdo, 'forum_name', $forumName);
        lf_seed_categories($pdo);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (lf_is_duplicate($e)) {
            return [$done, null];
        }
        throw $e;
    }
    return [null, $memberId];
}
