<?php
// The daily secret. An address is only ever turned into an anonymous code:
//   first 8 bytes of SHA-256(today's secret + purpose + address)
// The secret is random, made fresh each UTC day, and deleted afterwards (bin/maintain.php), so a code
// can't be linked to a person or to another day, not even by us. The address itself is never stored.

declare(strict_types=1);

function lf_salt(PDO $pdo, ?string $day = null): string
{
    $day ??= gmdate('Y-m-d');
    static $cache = [];
    if (isset($cache[$day])) {
        return $cache[$day];
    }
    // Two requests racing at midnight: INSERT IGNORE keeps the first secret, both read it back.
    $pdo->prepare("INSERT IGNORE INTO salts (day, salt) VALUES (:d, :s)")->execute(['d' => $day, 's' => random_bytes(32)]);
    $stmt = $pdo->prepare("SELECT salt FROM salts WHERE day = :d");
    $stmt->execute(['d' => $day]);
    return $cache[$day] = (string) $stmt->fetchColumn();
}

function lf_addr_code(string $salt, string $purpose, string $ip): string
{
    return substr(hash('sha256', $salt . "\0" . $purpose . "\0" . $ip, true), 0, 8);
}

// Deletes every secret older than today, which makes all earlier codes unlinkable for good.
function lf_salt_prune(PDO $pdo): int
{
    $stmt = $pdo->prepare("DELETE FROM salts WHERE day < :d");
    $stmt->execute(['d' => gmdate('Y-m-d')]);
    return $stmt->rowCount();
}

// The visitor's address: REMOTE_ADDR, or X-Forwarded-For's closest untrusted hop when the request came
// through a proxy we trust.
function lf_client_ip(array $server, array $trustedProxies): string
{
    $ip = (string) ($server['REMOTE_ADDR'] ?? '');
    if ($trustedProxies && in_array($ip, $trustedProxies, true) && !empty($server['HTTP_X_FORWARDED_FOR'])) {
        foreach (array_reverse(array_map('trim', explode(',', (string) $server['HTTP_X_FORWARDED_FOR']))) as $hop) {
            if (!in_array($hop, $trustedProxies, true) && filter_var($hop, FILTER_VALIDATE_IP)) {
                return $hop;
            }
        }
    }
    return $ip;
}
