<?php
// Creates LibreForum's tables. Safe to run again (existing tables are left as they are).
//   php bin/install.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/setup.php';

lf_install_schema(lf_db());
echo "Tables ready. Next, open the forum in your browser to set it up, or run:\n  php bin/owner.php yourname \"Forum name\"\n";
