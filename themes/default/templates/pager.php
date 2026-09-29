<?php /* Previous and next page. Vars: $page, $pages, $pagerBase (address without ?page=). */ ?>
<?php if ($pages > 1): ?>
<nav class="lf-pager" aria-label="Pages">
  <?php if ($page > 1): ?><a class="lf-btn" href="<?= h($pagerBase . ($page > 2 ? '?page=' . ($page - 1) : '')) ?>" rel="prev">← Previous</a><?php else: ?><span></span><?php endif; ?>
  <span class="lf-pager-count">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
  <?php if ($page < $pages): ?><a class="lf-btn" href="<?= h($pagerBase . '?page=' . ($page + 1)) ?>" rel="next">Next →</a><?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; ?>
