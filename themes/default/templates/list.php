<?php /* The thread list, for everything or one category. Vars: $category, $categories, $threads, $total, $page, $pages, $pagerBase, $canStart. */ ?>
<div class="lf-heading">
  <div>
    <h1><?= h($category['name'] ?? 'Latest threads') ?></h1>
    <p class="lf-sub"><?= $category !== null ? h($category['description']) : h(number_format($total) . ($total === 1 ? ' thread' : ' threads')) ?></p>
  </div>
</div>

<nav class="lf-pills" aria-label="Categories">
  <a href="<?= h(lf_url()) ?>"<?= $category === null ? ' aria-current="page"' : '' ?>>All</a>
  <?php foreach ($categories as $c): ?>
  <a href="<?= h(lf_url('c/' . $c['slug'])) ?>"<?= $category !== null && (int) $category['id'] === (int) $c['id'] ? ' aria-current="page"' : '' ?>><?= h($c['name']) ?> <span class="lf-pill-count"><?= (int) $c['thread_count'] ?></span></a>
  <?php endforeach; ?>
</nav>

<?php if ($threads === []): ?>
<div class="lf-card lf-empty">
  <p>No threads here yet.<?= $canStart ? ' Be the first to start one.' : '' ?></p>
</div>
<?php else: ?>
<ul class="lf-threads">
  <?php foreach ($threads as $t): ?>
  <li class="lf-thread<?= $t['pinned'] ? ' is-pinned' : '' ?><?= $t['unread'] ? ' is-unread' : '' ?>">
    <div class="lf-thread-main">
      <h2 class="lf-thread-title">
        <?php if ($t['pinned']): ?><span class="lf-flag" title="Pinned"><?= lf_icon('pin') ?><span class="lf-sr">Pinned:</span></span><?php endif; ?>
        <?php if ($t['locked']): ?><span class="lf-flag" title="Locked"><?= lf_icon('lock') ?><span class="lf-sr">Locked:</span></span><?php endif; ?>
        <a href="<?= h(lf_url('t/' . $t['id'])) ?>"><?= h($t['title']) ?></a>
        <?php if ($t['unread']): ?><span class="lf-tag lf-tag-new">New</span><?php endif; ?>
      </h2>
      <p class="lf-thread-meta">
        <?php if ($category === null): ?><a class="lf-chip" href="<?= h(lf_url('c/' . $t['category_slug'])) ?>"><?= h($t['category_name']) ?></a><?php endif; ?>
        Started by <b><?= h(lf_display_name($t['author'], $t['author_status'])) ?></b><?php if ($t['author_role'] !== 'member' && $t['author_status'] !== 'removed'): ?> <span class="lf-badge lf-badge-<?= h($t['author_role']) ?>"><?= $t['author_role'] === 'owner' ? 'Owner' : 'Moderator' ?></span><?php endif; ?>
        · <time datetime="<?= h(lf_iso($t['created_at'])) ?>"><?= h(lf_ago($t['created_at'])) ?></time>
      </p>
    </div>
    <div class="lf-thread-stats">
      <span class="lf-replies" title="Replies"><?= lf_icon('chat') ?> <?= (int) $t['post_count'] - 1 ?><span class="lf-sr"> replies</span></span>
      <span class="lf-last"><?php if ((int) $t['post_count'] > 1): ?>Last reply by <b><?= h(lf_display_name($t['last_author'], $t['last_author_status'])) ?></b>, <time datetime="<?= h(lf_iso($t['last_post_at'])) ?>"><?= h(lf_ago($t['last_post_at'])) ?></time><?php else: ?>No replies yet<?php endif; ?></span>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php lf_render('pager', get_defined_vars()); ?>
<?php endif; ?>
