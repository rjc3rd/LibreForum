<?php
// Creates the forum's owner and its first categories from the command line, instead of the setup page in
// the browser. Works once. Asks for the password (twice, not shown).
//   php bin/owner.php ranzy "Forum name"

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/cli.php';
lf_cli_guard();
require __DIR__ . '/../lib/setup.php';

[$username, $forum] = [trim($argv[1] ?? ''), trim($argv[2] ?? '')];
if ($username === '' || $forum === '') {
    exit("Usage: php bin/owner.php yourname \"Forum name\"\n");
}
$password = lf_cli_password();
$confirm = lf_cli_password('Type it again: ');
[$problem] = lf_setup_owner(lf_db(), $forum, $username, $password, $confirm);
echo $problem ?? "Done. $username is the owner of $forum. Log in in your browser.", "\n";
exit($problem === null ? 0 : 1);
