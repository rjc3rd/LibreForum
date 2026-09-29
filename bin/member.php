<?php
// People.
//   php bin/member.php list
//   php bin/member.php mute|unmute maya
//   php bin/member.php moderator|member maya     (change a role)
//   php bin/member.php remove maya               (what they wrote stays, as "Former member")
//   php bin/member.php password maya             (a new password for someone who lost theirs, asked for and not shown)
//   php bin/member.php host-only maya 42 [7]     (from now on maya comes in only through the app that runs the forum,
//                                                 whose id for her is 42, and 7 for her account (42 if left out):
//                                                 her password is erased and her logins end)

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/cli.php';
lf_cli_guard();
require __DIR__ . '/../lib/members.php';

$pdo = lf_db();
$owner = lf_cli_owner($pdo);
$cmd = $argv[1] ?? '';
switch ($cmd) {
    case 'list':
        foreach (lf_members_list($pdo) as $m) {
            echo str_pad((string) ($m['username'] ?? '(no username yet)'), 24), str_pad($m['role'], 12), str_pad($m['status'], 8), 'joined ', substr($m['created_at'], 0, 10), "\n";
        }
        break;
    case 'mute':
    case 'unmute':
    case 'moderator':
    case 'member':
    case 'remove':
        $id = lf_cli_member_id($pdo, $argv[2] ?? '');
        $problem = match ($cmd) {
            'mute' => lf_member_mute($pdo, $owner, $id, true),
            'unmute' => lf_member_mute($pdo, $owner, $id, false),
            'moderator', 'member' => lf_member_set_role($pdo, $owner, $id, $cmd),
            'remove' => lf_member_remove($pdo, $owner, $id),
        };
        echo $problem ?? 'Done.', "\n";
        exit($problem === null ? 0 : 1);
    case 'host-only':
        $id = lf_cli_member_id($pdo, $argv[2] ?? '');
        $problem = lf_member_make_host_only($pdo, $id, (string) ($argv[3] ?? ''), isset($argv[4]) ? (string) $argv[4] : null);
        echo $problem ?? 'Done. They come in only through the app that runs the forum now, with no password.', "\n";
        exit($problem === null ? 0 : 1);
    case 'password':
        $id = lf_cli_member_id($pdo, $argv[2] ?? '');
        $password = lf_cli_password();
        $problem = lf_password_problem($password, lf_cli_password('Type it again: '));
        if ($problem === null) {
            lf_password_set($pdo, $id, $password);
        }
        echo $problem ?? 'Password changed. Any logins they had were ended.', "\n";
        exit($problem === null ? 0 : 1);
    default:
        echo "Usage: php bin/member.php list | mute | unmute | moderator | member | remove | password | host-only <username> [host id]\n";
}
