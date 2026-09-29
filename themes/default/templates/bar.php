<?php /* Top bar: name, main links, new thread, who is logged in. Vars: $me, $forum, $path, $staff, $reportsOpen, $canWrite, $csrf. */
$here = fn (string ...$starts) => array_filter($starts, fn ($s) => $path === $s || str_starts_with($path, $s . '/')) ? ' aria-current="page"' : '';
?>
<header class="lf-bar"><div class="lf-bar-inner">
  <a class="lf-brand" href="<?= h(lf_url()) ?>"><?php lf_render('logo'); ?><span><?= h($forum) ?></span></a>
  <nav class="lf-nav" aria-label="Main">
    <a href="<?= h(lf_url()) ?>"<?= $path === '/' ? ' aria-current="page"' : $here('/c', '/t', '/new') ?>>Threads</a>
    <?php if ($staff): ?>
    <a href="<?= h(lf_url('reports')) ?>"<?= $here('/reports') ?>>Reports<?php if ($reportsOpen > 0): ?> <span class="lf-count" title="Reports waiting"><?= (int) $reportsOpen ?></span><?php endif; ?></a>
    <a href="<?= h(lf_url('manage')) ?>"<?= $here('/manage') ?>>Manage</a>
    <?php endif; ?>
  </nav>
  <span class="lf-bar-grow"></span>
  <?php if ($canWrite): ?><a class="lf-btn lf-btn-primary" href="<?= h(lf_url('new')) ?>"><?= lf_icon('plus') ?> New thread</a><?php endif; ?>
  <div class="lf-user">
    <a class="lf-me" href="<?= h(lf_url('settings')) ?>"<?= $here('/settings') ?>><?= h($me['username']) ?><?php if ($me['role'] !== 'member'): ?> <span class="lf-badge lf-badge-<?= h($me['role']) ?>"><?= $me['role'] === 'owner' ? 'Owner' : 'Moderator' ?></span><?php endif; ?></a>
    <?= lf_form('logout', $csrf) ?><button class="lf-btn" type="submit">Log out</button></form>
  </div>
</div></header>
