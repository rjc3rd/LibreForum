<?php
// Categories.
//   php bin/category.php list
//   php bin/category.php add "Off topic" "Anything else" [staff-only]
//   php bin/category.php rename off-topic "Chat"
//   php bin/category.php staff-only off-topic on|off       (only moderators start threads there)
//   php bin/category.php up|down off-topic
//   php bin/category.php delete off-topic                  (it has to be empty)

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/cli.php';
lf_cli_guard();
require __DIR__ . '/../lib/forum.php';

$pdo = lf_db();
$owner = lf_cli_owner($pdo);
$cmd = $argv[1] ?? '';
$category = isset($argv[2]) && $cmd !== 'add' ? lf_category_by_slug($pdo, $argv[2]) : null;
if (!in_array($cmd, ['list', 'add'], true) && $category === null) {
    exit(in_array($cmd, ['rename', 'staff-only', 'up', 'down', 'delete'], true) ? "There is no category with the address \"" . ($argv[2] ?? '') . "\". See: php bin/category.php list\n"
        : "Usage: php bin/category.php list | add | rename | staff-only | up | down | delete\n");
}
switch ($cmd) {
    case 'list':
        foreach (lf_categories($pdo) as $c) {
            echo str_pad($c['slug'], 24), str_pad($c['name'], 28), str_pad($c['thread_count'] . ' threads', 12), $c['staff_only'] ? 'only moderators start threads' : '', "\n";
        }
        break;
    case 'add':
        [$problem] = lf_category_add($pdo, $owner, $argv[2] ?? '', $argv[3] ?? '', ($argv[4] ?? '') === 'staff-only');
        echo $problem ?? 'Added.', "\n";
        break;
    case 'rename':
        echo lf_category_update($pdo, $owner, (int) $category['id'], $argv[3] ?? '', $category['description'], (bool) $category['staff_only']) ?? 'Renamed.', "\n";
        break;
    case 'staff-only':
        echo lf_category_update($pdo, $owner, (int) $category['id'], $category['name'], $category['description'], ($argv[3] ?? '') === 'on') ?? 'Saved.', "\n";
        break;
    case 'up':
    case 'down':
        echo lf_category_move($pdo, $owner, (int) $category['id'], $cmd === 'up' ? -1 : 1) ?? 'Moved.', "\n";
        break;
    case 'delete':
        echo lf_category_delete($pdo, $owner, (int) $category['id']) ?? 'Deleted.', "\n";
        break;
    default:
        echo "Usage: php bin/category.php list | add | rename | staff-only | up | down | delete\n";
}
