<?php
// Loads the settings and opens the database. Every entry point starts here.

declare(strict_types=1);

const LF_ROOT = __DIR__ . '/..';
const LF_VERSION = '0.1.0';

// The settings: config.php, or the file named by the LIBREFORUM_CONFIG environment variable (handy for
// containers). A program that embeds LibreForum, or the tests, can hand over the settings directly.
function lf_config(?array $set = null): array
{
    static $config = null;
    if ($set !== null) {
        $config = $set;
    }
    if ($config === null) {
        $file = getenv('LIBREFORUM_CONFIG') ?: LF_ROOT . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('LibreForum: config.php is missing (copy config.example.php).');
        }
        $config = require $file;
    }
    return $config;
}

// One setting by dotted path, e.g. lf_cfg('limits.posts_per_hour', 30).
function lf_cfg(string $path, mixed $default = null): mixed
{
    $value = lf_config();
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

function lf_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = lf_connect((array) lf_cfg('db'));
    }
    return $pdo;
}

// A connection for the given ['host', 'name', 'user', 'pass'].
function lf_connect(array $db): PDO
{
    $pdo = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

// Current time in UTC, the way the database stores it.
function lf_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

// Values the owner can change in the browser (forum name, house rules...), kept in the database.
function lf_setting(PDO $pdo, string $name, ?string $default = null): ?string
{
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = :n");
    $stmt->execute(['n' => $name]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function lf_setting_set(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare("INSERT INTO settings (name, value) VALUES (:n, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute(['n' => $name, 'v' => $value]);
}

function lf_setting_delete(PDO $pdo, string $name): void
{
    $pdo->prepare("DELETE FROM settings WHERE name = :n")->execute(['n' => $name]);
}

function lf_forum_name(PDO $pdo): string
{
    $name = trim((string) lf_setting($pdo, 'forum_name', ''));
    return $name !== '' ? $name : (string) lf_cfg('name', 'Forum');
}

// The default house rules, until the owner writes their own.
const LF_DEFAULT_RULES = "Be kind and stay on topic.\nNever post passwords or other private details.\nAccount and billing questions go to email, not here.";
