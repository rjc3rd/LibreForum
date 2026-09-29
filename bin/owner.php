<?php
// Creates the forum's owner and its first categories from the command line, instead of the setup page in
// the browser. Works once. Asks for the password (twice, not shown).
//   php bin/owner.php ranzy "Forum name"
//
// For a forum run inside another app, give that app's id for the person: the owner then gets NO password
// and can't log in on the forum's own page at all, only through that app.
//   php bin/owner.php ranzy "Forum name" --host-ref=2

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/cli.php';
lf_cli_guard();
require __DIR__ . '/../lib/setup.php';

$hostRef = null;
$words = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--host-ref=')) {
        $hostRef = substr($arg, strlen('--host-ref='));
    } else {
        $words[] = trim($arg);
    }
}
[$username, $forum] = [$words[0] ?? '', $words[1] ?? ''];
if ($username === '' || $forum === '') {
    exit("Usage: php bin/owner.php yourname \"Forum name\" [--host-ref=ID]\n");
}
$password = $confirm = '';
if ($hostRef === null) {
    $password = lf_cli_password();
    $confirm = lf_cli_password('Type it again: ');
}
[$problem] = lf_setup_owner(lf_db(), $forum, $username, $password, $confirm, $hostRef);
echo $problem ?? ($hostRef === null ? "Done. $username is the owner of $forum. Log in in your browser." : "Done. $username is the owner of $forum, with no password: they come in only through the app that runs it."), "\n";
exit($problem === null ? 0 : 1);
