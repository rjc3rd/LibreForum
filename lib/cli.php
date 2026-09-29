<?php
// Helpers for the tools in bin/.

declare(strict_types=1);

function lf_cli_guard(): void
{
    if (PHP_SAPI !== 'cli') {
        exit("Run from the command line.\n");
    }
}

// The owner, as the member the tools act for. Stops with a message when there is none yet.
function lf_cli_owner(PDO $pdo): array
{
    $id = $pdo->query("SELECT id FROM members WHERE role = 'owner' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    if ($id === false) {
        exit("There is no owner yet. Create one with: php bin/owner.php yourname \"Forum name\"\n");
    }
    return lf_member_get($pdo, (int) $id);
}

function lf_cli_member_id(PDO $pdo, string $username): int
{
    $stmt = $pdo->prepare("SELECT id FROM members WHERE username_key = :k AND status <> 'removed'");
    $stmt->execute(['k' => lf_username_key(trim($username))]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        exit("There is no member called $username.\n");
    }
    return (int) $id;
}

// Asks for a password without showing it (or reads a line when the input is piped in).
function lf_cli_password(string $prompt = 'Password (10 characters or more): '): string
{
    $tty = stream_isatty(STDIN);
    if ($tty) {
        echo $prompt;
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $password;
}

// A full address inside the forum, using 'url' from config.php when it is set.
function lf_cli_url(string $path): string
{
    $root = rtrim((string) lf_cfg('url', ''), '/');
    if ($root === '') {
        fwrite(STDERR, "(Add 'url' => 'https://your-forum-address' to config.php to print full addresses.)\n");
    }
    return $root . '/' . ltrim($path, '/');
}
