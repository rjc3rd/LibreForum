<?php /* A thread with its posts, the reply box and (for staff) the moderator tools.
   Vars: $thread, $posts, $page, $pages, $pagerBase, $firstId, $firstNew, $canReply, $compose, $categories,
   $othersReplied, $me, $staff, $canWrite, $csrf. */
$tid = (int) $thread['id'];
?>
<nav class="lf-crumbs" aria-label="Breadcrumb">
  <a href="<?= h(lf_url()) ?>">Threads</a><span aria-hidden="true">/</span><a href="<?= h(lf_url('c/' . $thread['category_slug'])) ?>"><?= h($thread['category_name']) ?></a>
</nav>
<div class="lf-heading">
  <div>
    <h1><?= h($thread['title']) ?></h1>
    <p class="lf-sub">
      <?php if ($thread['pinned']): ?><span class="lf-chip lf-chip-flag"><?= lf_icon('pin') ?> Pinned</span> <?php endif; ?>
      <?php if ($thread['locked']): ?><span class="lf-chip lf-chip-flag"><?= lf_icon('lock') ?> Locked</span> <?php endif; ?>
      <?= (int) $thread['post_count'] ?> <?= (int) $thread['post_count'] === 1 ? 'post' : 'posts' ?>
    </p>
  </div>
</div>

<?php if ($staff): ?>
<section class="lf-modbar" aria-label="Moderator tools">
  <span class="lf-modbar-label"><?= lf_icon('shield') ?> Moderator tools</span>
  <?= lf_form("t/$tid/" . ($thread['pinned'] ? 'unpin' : 'pin'), $csrf, 'lf-inline') ?><button class="lf-btn lf-btn-small" type="submit"><?= lf_icon('pin') ?> <?= $thread['pinned'] ? 'Unpin' : 'Pin' ?></button></form>
  <?= lf_form("t/$tid/" . ($thread['locked'] ? 'unlock' : 'lock'), $csrf, 'lf-inline') ?><button class="lf-btn lf-btn-small" type="submit"><?= lf_icon('lock') ?> <?= $thread['locked'] ? 'Unlock' : 'Lock' ?></button></form>
  <?= lf_form("t/$tid/move", $csrf, 'lf-inline') ?>
    <label class="lf-sr" for="lf-move">Move to</label>
    <select class="lf-select lf-select-small" id="lf-move" name="category">
      <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $thread['category_id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="lf-btn lf-btn-small" type="submit"><?= lf_icon('folder') ?> Move</button>
  </form>
  <?= lf_form("t/$tid/delete", $csrf, 'lf-inline', 'Delete this whole thread?') ?><button class="lf-btn lf-btn-small lf-btn-danger" type="submit"><?= lf_icon('trash') ?> Delete thread</button></form>
</section>
<?php endif; ?>

<?php foreach ($posts as $p):
    $pid = (int) $p['id'];
    $mine = (int) $p['member_id'] === (int) $me['id'];
    $isFirst = $pid === $firstId;
    $name = lf_display_name($p['username'], $p['member_status']);
    $canDelete = $staff || ($mine && (!$isFirst || !$othersReplied));
    $canReport = $canWrite && !$mine && !$staff;
?>
  <?php if ($firstNew === $pid): ?><div class="lf-newmark" role="separator"><span>New since your last visit</span></div><?php endif; ?>
  <article class="lf-post<?= $isFirst ? ' is-first' : '' ?><?= $firstNew !== null && $pid >= $firstNew ? ' is-new' : '' ?>" id="post-<?= $pid ?>">
    <header class="lf-post-head">
      <span class="lf-avatar lf-av-<?= crc32($name) % 6 ?>" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
      <span class="lf-author"><?= h($name) ?></span>
      <?php if ($p['member_role'] !== 'member' && $p['member_status'] !== 'removed'): ?><span class="lf-badge lf-badge-<?= h($p['member_role']) ?>"><?= $p['member_role'] === 'owner' ? 'Owner' : 'Moderator' ?></span><?php endif; ?>
      <a class="lf-when" href="#post-<?= $pid ?>"><time datetime="<?= h(lf_iso($p['created_at'])) ?>"><?= h(lf_ago($p['created_at'])) ?></time></a>
      <?php if ($firstNew !== null && $pid >= $firstNew): ?><span class="lf-tag lf-tag-new">New</span><?php endif; ?>
    </header>
    <div class="lf-body"><?= lf_body_html($p['body']) ?></div>
    <?php if ($canReport || $canDelete): ?>
    <footer class="lf-post-actions">
      <?php if ($canReport): ?><?= lf_form("p/$pid/report", $csrf, 'lf-inline', 'Report this post to the moderators?') ?><button class="lf-link" type="submit"><?= lf_icon('flag') ?> Report</button></form><?php endif; ?>
      <?php if ($canDelete): ?><?= lf_form("p/$pid/delete", $csrf, 'lf-inline', $isFirst ? 'Delete this whole thread?' : 'Delete this post?') ?><button class="lf-link" type="submit"><?= lf_icon('trash') ?> Delete</button></form><?php endif; ?>
    </footer>
    <?php endif; ?>
  </article>
<?php endforeach; ?>

<?php lf_render('pager', get_defined_vars()); ?>

<?php if ($canReply): ?>
<section class="lf-card lf-compose" aria-labelledby="lf-reply-h">
  <h2 id="lf-reply-h">Reply</h2>
  <?php if (!empty($compose['error'])): ?><p class="lf-error" role="alert"><?= h($compose['error']) ?></p><?php endif; ?>
  <?= lf_form("t/$tid/reply", $csrf, 'lf-form') ?>
    <label class="lf-sr" for="lf-body">Your reply</label>
    <textarea class="lf-input lf-textarea" id="lf-body" name="body" rows="5" maxlength="10000" required><?= h($compose['body'] ?? '') ?></textarea>
    <p class="lf-hint">A blank line starts a new paragraph. Put `backticks` around code, or ``` on a line of its own above and below a block. Web addresses become links.</p>
    <div><button class="lf-btn lf-btn-primary" type="submit">Post reply</button></div>
  </form>
</section>
<?php elseif ($thread['locked']): ?>
<p class="lf-notice"><?= lf_icon('lock') ?> This thread is locked. Only moderators can reply.</p>
<?php elseif (!$canWrite): ?>
<p class="lf-notice">You are muted, so you can read but not write.</p>
<?php endif; ?>
