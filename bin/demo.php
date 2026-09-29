<?php
// Fills an empty forum with pretend people and conversations, to look at a theme or to try things out.
// Never run it on a forum people really use.
//   php bin/demo.php
// The owner is "demo_owner" and everyone's password is "demo-password-123".

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/cli.php';
lf_cli_guard();
require __DIR__ . '/../lib/setup.php';
require __DIR__ . '/../lib/forum.php';

const DEMO_PASSWORD = 'demo-password-123';

$pdo = lf_db();
if ((int) $pdo->query("SELECT COUNT(*) FROM threads")->fetchColumn() > 0) {
    exit("This forum already has threads. The demo only fills an empty one.\n");
}
// The demo posts fast, so the limits for ordinary members don't apply to it.
lf_config(array_replace_recursive(lf_config(), ['limits' => ['seconds_between_posts' => 0, 'posts_per_hour' => 100000, 'threads_per_day' => 100000]]));

if (!lf_owner_exists($pdo)) {
    [$problem] = lf_setup_owner($pdo, 'Demo Forum', 'demo_owner', DEMO_PASSWORD, DEMO_PASSWORD);
    if ($problem !== null) {
        exit("$problem\n");
    }
}
$owner = lf_cli_owner($pdo);
$account = (int) $owner['account_id'];
$people = ['demo_owner' => $owner];
foreach (['sam' => 'moderator', 'maya' => 'member', 'jonas' => 'member', 'priya' => 'member', 'tomas' => 'member'] as $name => $role) {
    $id = lf_member_create($pdo, $account, $name, password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT), $role);
    $pdo->prepare("UPDATE members SET created_at = :t WHERE id = :m")->execute(['t' => gmdate('Y-m-d H:i:s', time() - 8 * 86400), 'm' => $id]);
    $people[$name] = lf_member_get($pdo, $id);
}
$category = fn (string $slug): int => (int) lf_category_by_slug($pdo, $slug)['id'];

// [category, author, title, opening post, minutes ago, [[author, reply, minutes ago], ...], pinned, locked]
$conversations = [
    ['announcements', 'demo_owner', 'Welcome to the forum', "Glad you are here. This is a place to ask questions, share what you have built and help each other.\n\nPlease read the house rules first (there is a link at the bottom of every page). If something looks wrong, use the Report link under a post and a moderator will take a look.", 8 * 1440, [
        ['maya', 'Thanks! Happy to be here.', 7 * 1440 + 30],
    ], true, false],
    ['announcements', 'sam', 'Short maintenance window on Sunday morning', "The servers will restart for updates on Sunday between 6 and 7 am. Websites and email may be unavailable for a few minutes.\n\nNothing you need to do.", 2 * 1440, [
        ['jonas', 'Good to know, thanks for the warning.', 2 * 1440 - 40],
        ['priya', 'Will webmail be down too?', 2 * 1440 - 95],
        ['sam', 'Yes, for a few minutes. Everything comes back on its own.', 2 * 1440 - 120],
    ], false, true],
    ['help', 'maya', 'How do I set up my email on my phone?', "I created my mailbox yesterday and I can read it in the browser, but my phone keeps saying the password is wrong. Which settings do I use?", 3 * 1440, [
        ['sam', "Use these settings, with your full email address as the username:\n\n```\nIncoming (IMAP): mail.example.com, port 993, SSL/TLS\nOutgoing (SMTP): mail.example.com, port 587, STARTTLS\n```\n\nIf the password is still refused, try typing it again slowly. Phones love to add a capital letter at the start.", 3 * 1440 - 50],
        ['maya', 'That was it, the phone had capitalized the first letter. Working now, thank you!', 3 * 1440 - 200],
    ], false, false],
    ['help', 'jonas', 'My site shows a blank page after I upload it', "I uploaded my new pages with FTP and now the home page is completely white. No error, nothing. It worked on my computer.", 26 * 60, [
        ['priya', "A blank page usually means PHP hit an error and is hiding it. Look at the newest lines of the error log (in the `logs` folder of your site):\n\n```\ntail -n 20 logs/error.log\n```\n\nThe last line normally names the file and the line number.", 25 * 60],
        ['jonas', 'Found it: `PHP Parse error: syntax error, unexpected end of file in index.php on line 42`. I lost a closing brace when I edited it. Fixed, thanks!', 22 * 60],
        ['tomas', 'Tip: running `php -l index.php` on your own computer checks a file for syntax errors before you upload it. There is more about it here: https://www.php.net/manual/en/features.commandline.options.php', 20 * 60],
    ], false, false],
    ['help', 'tomas', 'Can I use a different PHP version for each website?', "I have two sites. One is old and needs PHP 7.4, the other one is new. Is that possible?", 5 * 60, [], false, false],
    ['show-your-site', 'priya', 'A portfolio for my photography, feedback welcome', "I finally put my photos online. It is plain HTML with a bit of CSS and no plugins.\n\nhttps://example.com/portfolio\n\nWhat would you change first?", 2 * 1440 + 300, [
        ['tomas', 'Really clean. The photos load quickly. I would make the captions a little bigger on a phone.', 2 * 1440 + 200],
        ['maya', 'Love the second gallery. Maybe add a contact link at the top?', 2 * 1440 + 100],
        ['jonas', 'Same as Maya. Otherwise it is great.', 2 * 1440],
        ['priya', 'Thank you all, I will do both.', 2 * 1440 - 60],
    ], false, false],
    ['show-your-site', 'tomas', 'A recipe box for my grandmother', 'She dictates, I type. It has 31 recipes so far and a search box that I wrote in one afternoon.', 90, [], false, false],
];
foreach ($conversations as [$slug, $author, $title, $text, $minutes, $replies, $pinned, $locked]) {
    [, $threadId] = lf_thread_create($pdo, $people[$author], $category($slug), $title, $text);
    $times = [$minutes];
    foreach ($replies as [$who, $reply, $when]) {
        lf_reply_create($pdo, $people[$who], (int) $threadId, $reply);
        $times[] = $when;
    }
    // Spread the posts over the past days, oldest first.
    $ids = $pdo->query("SELECT id FROM posts WHERE thread_id = $threadId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $i => $postId) {
        $pdo->prepare("UPDATE posts SET created_at = :t WHERE id = :p")->execute(['t' => gmdate('Y-m-d H:i:s', time() - $times[$i] * 60), 'p' => $postId]);
    }
    $pdo->prepare("UPDATE threads SET created_at = :c, last_post_at = :l WHERE id = :t")->execute(['c' => gmdate('Y-m-d H:i:s', time() - $times[0] * 60), 'l' => gmdate('Y-m-d H:i:s', time() - end($times) * 60), 't' => $threadId]);
    if ($pinned) {
        lf_thread_set($pdo, $owner, (int) $threadId, 'pinned', true);
    }
    if ($locked) {
        lf_thread_set($pdo, $owner, (int) $threadId, 'locked', true);
    }
}
// One report to look at, for the moderator pages.
$tomasReply = (int) $pdo->query("SELECT p.id FROM posts p JOIN members m ON m.id = p.member_id WHERE m.username = 'tomas' AND p.body LIKE 'Tip:%'")->fetchColumn();
lf_report_add($pdo, $people['maya'], $tomasReply);
echo "Done. Log in as demo_owner (owner), sam (moderator) or maya, jonas, priya, tomas (members). Password: ", DEMO_PASSWORD, "\n";
