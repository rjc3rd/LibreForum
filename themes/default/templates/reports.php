<?php /* Posts members have reported. Vars: $reports, $csrf. */ ?>
<div class="lf-heading"><h1>Reports</h1><p class="lf-sub">Posts members asked a moderator to look at. Who reported is never shown.</p></div>
<?php if ($reports === []): ?>
<div class="lf-card lf-empty"><p>Nothing has been reported. All quiet.</p></div>
<?php else: ?>
<ul class="lf-reports">
  <?php foreach ($reports as $r): $pid = (int) $r['post_id']; ?>
  <li class="lf-card lf-report">
    <p class="lf-report-meta">
      <b><?= (int) $r['reports'] ?> <?= (int) $r['reports'] === 1 ? 'report' : 'reports' ?></b>
      · post by <b><?= h(lf_display_name($r['username'], $r['member_status'])) ?></b> in <a href="<?= h($r['link']) ?>"><?= h($r['title']) ?></a>
      · first reported <time datetime="<?= h(lf_iso($r['first_reported'])) ?>"><?= h(lf_ago($r['first_reported'])) ?></time>
    </p>
    <blockquote class="lf-quote"><?= h(lf_excerpt($r['body'], 400)) ?></blockquote>
    <div class="lf-actions">
      <a class="lf-btn lf-btn-small" href="<?= h($r['link']) ?>">View in the thread</a>
      <?= lf_form("reports/$pid/resolve", $csrf, 'lf-inline') ?><button class="lf-btn lf-btn-small" type="submit"><?= lf_icon('check') ?> It’s fine</button></form>
      <?= lf_form("p/$pid/delete", $csrf, 'lf-inline', 'Delete this post?') ?><input type="hidden" name="back" value="reports"><button class="lf-btn lf-btn-small lf-btn-danger" type="submit"><?= lf_icon('trash') ?> Delete post</button></form>
      <?php if ($r['member_role'] === 'member' && $r['member_status'] === 'active'): ?>
      <?= lf_form('manage/members/' . (int) $r['member_id'] . '/mute', $csrf, 'lf-inline', 'Mute this member? They can still read, but not write.') ?><input type="hidden" name="back" value="reports"><button class="lf-btn lf-btn-small" type="submit">Mute the author</button></form>
      <?php endif; ?>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
