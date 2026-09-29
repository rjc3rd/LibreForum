<?php
// LibreForum settings. Copy to config.php (kept out of git) and fill in.

return [
    // Database (MariaDB or MySQL).
    'db' => [
        'host' => 'localhost',
        'name' => 'libreforum',
        'user' => 'libreforum',
        'pass' => '',
    ],

    // The forum's name, until the owner sets one in the browser (Manage > Forum).
    'name' => 'Community',

    // The forum's full address, used when the command-line tools print links (php bin/invite.php).
    // No slash at the end. Leave it empty and the tools print only the last part of the address.
    'url' => '',

    // Names members can't pick (compared without capitals or underscores). The forum's own name is always reserved too.
    'reserved_names' => ['admin', 'administrator', 'staff', 'moderator', 'mod', 'support', 'root', 'system', 'owner'],

    // Limits for ordinary members: posts per hour, new threads per day, seconds between two posts.
    // The owner and moderators are never limited.
    'limits' => ['posts_per_hour' => 30, 'threads_per_day' => 10, 'seconds_between_posts' => 5],

    // How many days you stay logged in without visiting.
    'session_days' => 30,

    // Threads per page on the list, posts per page in a thread.
    'threads_per_page' => 25,
    'posts_per_page' => 25,

    // Behind a reverse proxy or CDN, list its addresses so the visitor's real address (used only for
    // the wrong-password limit, as an anonymous daily code, never stored) is read from X-Forwarded-For.
    'trusted_proxies' => [],

    // Look: a folder name under themes/ (or under one of theme_paths).
    'theme' => 'default',
    'theme_paths' => [],

    // Where the footer's "Source code" link points. If you run a changed copy, point it at your own source.
    'source_url' => 'https://github.com/rjc3rd/LibreForum',
];
