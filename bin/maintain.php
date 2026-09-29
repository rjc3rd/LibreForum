<?php
// Daily housekeeping. Run it once a day from cron, for example:
//   15 3 * * *  php /path/to/libreforum/bin/maintain.php --quiet
// It removes old logins, yesterday's daily secrets, old login failures, used-up invitations, and things
// people deleted more than 30 days ago.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/maintain.php';

$log = lf_maintain(lf_db());
if (!in_array('--quiet', $argv, true)) {
    echo implode("\n", $log), "\n";
}
