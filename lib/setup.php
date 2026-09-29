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

function lf_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c");
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (bool) $stmt->fetchColumn();
}

function lf_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i");
    $stmt->execute(['t' => $table, 'i' => $index]);
    return (bool) $stmt->fetchColumn();
}

// Brings tables made by an older version up to date (columns the schema file has and they lack). A new
// install already has them all. Returns a line for each change it made.
function lf_migrate(PDO $pdo): array
{
    $changes = [];
    if (!lf_column_exists($pdo, 'sessions', 'via_host')) {
        $pdo->exec("ALTER TABLE sessions ADD COLUMN via_host TINYINT(1) NOT NULL DEFAULT 0 AFTER csrf");
        $changes[] = 'sessions.via_host added';
    }
    if (!lf_column_exists($pdo, 'accounts', 'host_ref')) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN host_ref VARCHAR(100) NULL AFTER member_limit");
        $changes[] = 'accounts.host_ref added';
    }
    if (!lf_column_exists($pdo, 'accounts', 'suspended_at')) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN suspended_at DATETIME NULL AFTER host_ref");
        $changes[] = 'accounts.suspended_at added';
    }
    if (!lf_column_exists($pdo, 'invites', 'note')) {
        $pdo->exec("ALTER TABLE invites ADD COLUMN note VARCHAR(100) NOT NULL DEFAULT '' AFTER created_by");
        $changes[] = 'invites.note added';
    }
    if (!lf_index_exists($pdo, 'accounts', 'uq_accounts_host')) {
        $pdo->exec("ALTER TABLE accounts ADD UNIQUE KEY uq_accounts_host (host_ref)");
        $changes[] = 'accounts.host_ref made unique';
    }
    return $changes;
}

// Creates the tables (ones that already exist are left as they are) and updates older ones.
function lf_install_schema(PDO $pdo): array
{
    foreach (lf_schema_statements() as $statement) {
        $pdo->exec($statement);
    }
    return lf_migrate($pdo);
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
// [message, null] or [null, member id]. With $hostRef (the id an app that runs the forum uses for this
// person) the owner gets NO password: they can't log in on the forum's own page at all, only through that app.
function lf_setup_owner(PDO $pdo, string $forumName, string $username, string $password, string $confirm, ?string $hostRef = null): array
{
    $forumName = trim(preg_replace('~\s+~u', ' ', lf_clean_text($forumName)) ?? '');
    $username = trim($username);
    if ($forumName === '' || mb_strlen($forumName) > 60) {
        return ['Please name your forum (up to 60 characters).', null];
    }
    if ($hostRef !== null && !preg_match(LF_HOST_ID, $hostRef)) {
        return ['That host id isn’t valid.', null];
    }
    $problem = lf_username_problem($pdo, $username, true) ?? ($hostRef === null ? lf_password_problem($password, $confirm) : null);
    if ($problem !== null) {
        return [$problem, null];
    }
    $done = 'This forum has already been set up.';
    if (lf_owner_exists($pdo)) {
        return [$done, null];
    }
    $hash = $hostRef === null ? password_hash($password, PASSWORD_DEFAULT) : null;
    $pdo->beginTransaction();
    try {
        // A second visitor arriving at the same moment hits this unique key and stops here.
        $pdo->prepare("INSERT INTO settings (name, value) VALUES ('owner_created', :t)")->execute(['t' => lf_now()]);
        $accountId = lf_account_create($pdo, $forumName);
        $memberId = lf_member_create($pdo, $accountId, $username, $hash, 'owner', $hostRef);
        if ($hostRef !== null) {
            // The host's account and its person are the same thing here, so its team can find this account.
            $pdo->prepare("UPDATE accounts SET host_ref = :h WHERE id = :a")->execute(['h' => $hostRef, 'a' => $accountId]);
        }
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
