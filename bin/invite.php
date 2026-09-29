<?php
// Makes an invitation link and prints it. It works once. Only a hash of it is kept, so this is the only
// time it can be shown.
//   php bin/invite.php                 (a member, valid for 7 days)
//   php bin/invite.php moderator 30    (a moderator, valid for 30 days)

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/cli.php';
lf_cli_guard();
require __DIR__ . '/../lib/members.php';

$pdo = lf_db();
[$problem, $token] = lf_invite_create($pdo, lf_cli_owner($pdo), $argv[1] ?? 'member', (int) ($argv[2] ?? 7));
echo $problem ?? lf_cli_url('invite/' . $token), "\n";
exit($problem === null ? 0 : 1);
