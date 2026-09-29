<?php /* The page around every template: head, top bar, one-time message, footer. Vars: $title, $content, $me, $forum, $flash, $bare, $sourceUrl, $version. */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="<?= h(lf_asset('theme.css')) ?>">
<?php if (lf_theme_file('assets', 'custom.css', true)): ?><link rel="stylesheet" href="<?= h(lf_asset('custom.css')) ?>"><?php endif; ?>
<link rel="icon" href="<?= h(lf_asset('icon.svg')) ?>" type="image/svg+xml">
</head>
<body<?= !empty($embedded) ? ' class="lf-embedded"' : '' ?>>
<a class="lf-skip" href="#main">Skip to the content</a>
<?php if ($me !== null && empty($bare)) { lf_render('bar', get_defined_vars()); } ?>
<main class="lf-main<?= !empty($bare) ? ' lf-main-bare' : '' ?>" id="main">
<?php if (!empty($flash)): ?><p class="lf-flash lf-flash-<?= h($flash[0]) ?>" role="status"><?= h($flash[1]) ?></p><?php endif; ?>
<?= $content ?>
</main>
<footer class="lf-foot">
<?php if ($me !== null): ?><a href="<?= h(lf_url('rules')) ?>">House rules</a> · <?php endif; ?>Powered by LibreForum <?= h($version) ?> · <a href="<?= h($sourceUrl) ?>" rel="noopener noreferrer">Source code</a>
</footer>
<script src="<?= h(lf_asset('forum.js')) ?>" defer></script>
</body>
</html>
