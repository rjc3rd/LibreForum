# LibreForum

A small forum you host yourself, with nothing going to anyone else. Free and open source.

LibreForum is for communities that want to talk to each other without handing anything to a third party: no tracking, no ads, no outside scripts, fonts or avatars. It is one PHP app and one MariaDB or MySQL database.

> **Status:** early development. Everything below works end to end and is covered by tests, but expect changes before the first release.

## What it does

- Categories, threads and replies, in plain text. Web addresses become links, and `code` and code blocks keep their spacing.
- Members only. People join with a one-time invitation link and choose a username. No email address is asked for or stored.
- Announcements: a category can be set so that only moderators start threads in it, while everyone can reply.
- Moderation: pin, lock, move and delete threads, mute a member (they can still read), and a Report link under every post that feeds a queue for moderators.
- "New since your last visit" markers, so you can see what changed in a thread.
- Members can delete their own posts. A member who leaves is shown as "Former member" and their posts stay.
- Limits on how fast members can post. Moderators are never limited.
- A clean default look that follows the device's light or dark setting, and themes you can swap or adjust with a few color and font settings.

## Privacy

- **No third parties.** No outside fonts, scripts, avatars or analytics. Every page is sent with a policy that stops the browser loading anything from anywhere else.
- **No IP addresses stored.** Limits on wrong passwords use an anonymous code that changes every day.
- **Only the cookie it needs to keep you logged in.** Only a scrambled copy of it is kept on the server.
- **No email addresses.** Nothing is sent by mail, and members can't see anything about each other except a username.
- **Not for search engines.** Every page says "noindex", and links to other sites don't tell them where the visitor came from.
- **Deleted really means deleted.** Deleted threads and posts are hidden at once and removed from the database after 30 days.

## Requirements

PHP 8.2 or newer and MySQL or MariaDB. No other services.

## Installing

1. Put the whole folder on your server and make **`public/`** the website's root. Everything else (code, settings, tools) must stay outside it. The included `.htaccess` is for Apache. For nginx:

   ```nginx
   root /path/to/libreforum/public;
   location / { try_files $uri /index.php?$query_string; }
   location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
   ```

   It also works in a sub-folder of a site (`example.com/forum/`).
2. Create a MariaDB/MySQL database and user, copy `config.example.php` to `config.php`, and fill in the database details and the forum's name.
3. Create the tables: `php bin/install.php`
4. Open the forum's address in a browser. The first visitor names the forum and creates the owner's login. (Or do it from the command line: `php bin/owner.php yourname "Forum name"`. Do one of the two right after installing, before anyone else finds the address.)
5. Add a cron job that runs once a day: `php bin/maintain.php --quiet`
6. Use HTTPS, and turn `display_errors` off in PHP's settings on a live server.

Log in as the owner, open **Manage → Invitations**, and make a link for each person you want to invite. The link works once and is shown only once. When someone opens it they see the house rules, choose a username and a password, and they are in.

To try it on your own computer without a web server, run `php -S 127.0.0.1:8080 -t public bin/router.php` in this folder and open http://127.0.0.1:8080.

Want to see it with some conversations in it first? `php bin/demo.php` fills an empty forum with made-up people and threads (owner `demo_owner`, everyone's password `demo-password-123`). Don't run it on a forum people really use.

## Roles

- **Owner:** everything. Invites people, makes moderators, removes members, and edits the categories, the forum's name and the house rules.
- **Moderator:** pins, locks, moves and deletes, mutes members, and works through reports. Can start threads in announcement categories.
- **Member:** starts threads, replies, reports posts, deletes their own posts. A member can delete their own thread as long as nobody else has replied to it.

## Command-line tools

| Command | What it does |
| --- | --- |
| `php bin/install.php` | Creates the tables. Safe to run again. |
| `php bin/owner.php name "Forum name"` | Creates the owner, instead of the setup page. |
| `php bin/invite.php [member\|moderator] [days]` | Prints an invitation link. |
| `php bin/category.php list \| add \| rename \| staff-only \| up \| down \| delete` | Manages categories. |
| `php bin/member.php list \| mute \| unmute \| moderator \| member \| remove \| password` | Manages people. `password` sets a new password for someone who lost theirs. |
| `php bin/maintain.php [--quiet]` | Daily housekeeping. |
| `php bin/demo.php` | Fills an empty forum with pretend conversations. |
| `php -S 127.0.0.1:8080 -t public bin/router.php` | Runs the forum on your own computer with PHP's built-in web server, for trying it out. |

## Settings

`config.example.php` lists every setting with a comment. The ones you are most likely to change:

- `name`: the forum's name until the owner sets one in the browser.
- `url`: the forum's full address, used when the command-line tools print links.
- `limits`: posts per hour, new threads per day and seconds between posts for ordinary members.
- `reserved_names`: usernames nobody can pick (the forum's own name is always reserved).
- `trusted_proxies`: if the forum sits behind a reverse proxy or CDN, list its addresses so the visitor's real address is used for the wrong-password limit.
- `theme` and `theme_paths`: see below.

Settings can also be given from a different file with the `LIBREFORUM_CONFIG` environment variable, which is handy in containers.

## Themes

LibreForum ships with a clean default theme. Every color, font and size is a token at the top of `themes/default/assets/theme.css`, so you can make it match your own site without touching the code.

- **Small changes:** make a folder `themes/mine/assets/` with a `custom.css` that overrides the tokens, and set `'theme' => 'mine'` in `config.php`.
- **Bigger changes:** copy any page from `themes/default/templates/` into your theme and edit it. Anything a theme doesn't have comes from the default theme.
- Themes can live outside the code folder: put `'theme_paths' => ['/path/to/my/themes']` in `config.php`.

## Checks

The tests need a separate MariaDB/MySQL user and two throwaway databases (they are wiped on every run). `tests/config.example.php` says how to set them up.

```
php tests/run.php     # the library, against a database
php tests/e2e.php     # the whole forum over HTTP, plus the command-line tools
```

## License

Copyright (C) 2026 RJC3rd.

[GNU Affero General Public License v3.0](LICENSE). You're free to use, study, change, and share LibreForum. If you share it, or run a changed version for other people over a network, you must keep it under the same license and share your source too, so it stays free for everyone. The footer's "Source code" link is there for that: if you run a changed copy, point `source_url` in `config.php` at your own source.
