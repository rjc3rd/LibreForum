<?php
// Housekeeping, run once a day (bin/maintain.php): old logins, old daily secrets and login failures,
// used-up invitations, and things people deleted, which leave for good after 30 days.

declare(strict_types=1);

require_once __DIR__ . '/salt.php';

const LF_DELETED_KEEP_DAYS = 30;

// Returns a line for each thing it cleaned up.
function lf_maintain(PDO $pdo): array
{
    $log = [];
    $cut = fn (int $days) => gmdate('Y-m-d H:i:s', time() - $days * 86400);

    $stmt = $pdo->prepare("DELETE FROM sessions WHERE last_used_at < :t");
    $stmt->execute(['t' => $cut((int) lf_cfg('session_days', 30))]);
    $log[] = $stmt->rowCount() . ' expired logins removed';

    $stmt = $pdo->prepare("DELETE FROM sessions WHERE via_host = 1 AND (last_used_at < :i OR created_at < :m)");
    $stmt->execute(['i' => gmdate('Y-m-d H:i:s', time() - (int) lf_cfg('host.idle_minutes', 60) * 60), 'm' => gmdate('Y-m-d H:i:s', time() - (int) lf_cfg('host.max_hours', 12) * 3600)]);
    $log[] = $stmt->rowCount() . ' expired host logins removed';
    $stmt = $pdo->prepare("DELETE FROM host_tokens WHERE expires_at < :t");
    $stmt->execute(['t' => lf_now()]);
    $log[] = $stmt->rowCount() . ' used entry links forgotten';

    $log[] = lf_salt_prune($pdo) . ' old daily secrets removed';
    $stmt = $pdo->prepare("DELETE FROM login_failures WHERE at < :t");
    $stmt->execute(['t' => $cut(1)]);
    $log[] = $stmt->rowCount() . ' old login failures removed';

    $stmt = $pdo->prepare("DELETE FROM invites WHERE (used_at IS NOT NULL AND used_at < :t1) OR expires_at < :t2");
    $stmt->execute(['t1' => $cut(30), 't2' => $cut(30)]);
    $log[] = $stmt->rowCount() . ' old invitations removed';

    // Posts and reports of a deleted thread go with it (foreign keys).
    $stmt = $pdo->prepare("DELETE FROM threads WHERE deleted_at < :t");
    $stmt->execute(['t' => $cut(LF_DELETED_KEEP_DAYS)]);
    $threads = $stmt->rowCount();
    $stmt = $pdo->prepare("DELETE FROM posts WHERE deleted_at < :t");
    $stmt->execute(['t' => $cut(LF_DELETED_KEEP_DAYS)]);
    $log[] = $threads . ' deleted threads and ' . $stmt->rowCount() . ' deleted posts purged';

    return $log;
}
