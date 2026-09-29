<?php
// Copy this file to tests/config.php (which git ignores) and fill it in.
//
// The tests wipe every table in the two databases named here, so give them databases (and a MariaDB or
// MySQL user) of their own, never your real forum's. Their names must end in _test or _e2e.
//
//   CREATE DATABASE libreforum_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
//   CREATE DATABASE libreforum_e2e  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
//   CREATE USER 'libreforum_test'@'localhost' IDENTIFIED BY 'a-password';
//   GRANT ALL PRIVILEGES ON libreforum_test.* TO 'libreforum_test'@'localhost';
//   GRANT ALL PRIVILEGES ON libreforum_e2e.*  TO 'libreforum_test'@'localhost';

return [
    'host' => 'localhost',
    'user' => 'libreforum_test',
    'pass' => '',
    'unit_db' => 'libreforum_test',   // php tests/run.php
    'e2e_db' => 'libreforum_e2e',     // php tests/e2e.php
];
