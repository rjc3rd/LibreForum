<?php /* Invitation links. Vars: $invites (open ones), $seats (used, limit), $newLink (shown once, right after it is made), $csrf. */ ?>
<?php if ($newLink !== null): ?>
<section class="lf-card lf-notice-card">
  <h2>Your invitation link</h2>
  <p>Send this to the person you are inviting. It works once. Only a scrambled copy is kept here, so this is the only time it can be shown.</p>
  <div class="lf-copy">
    <input class="lf-input" type="text" readonly value="<?= h($newLink) ?>" aria-label="Invitation link">
    <button class="lf-btn" type="button" data-copy="<?= h($newLink) ?>"><?= lf_icon('copy') ?> Copy</button>
  </div>
</section>
<?php endif; ?>

<section class="lf-card">
  <h2>Invite someone</h2>
  <?php if ($seats['limit'] !== null): ?><p class="lf-hint"><?= (int) $seats['used'] ?> of <?= (int) $seats['limit'] ?> places used.</p><?php endif; ?>
  <?= lf_form('manage/invites', $csrf, 'lf-form lf-form-row') ?>
    <label>They join as
      <select class="lf-select" name="role"><option value="member">Member</option><option value="moderator">Moderator</option></select>
    </label>
    <label>The link works for
      <select class="lf-select" name="days"><option value="1">1 day</option><option value="7" selected>7 days</option><option value="30">30 days</option></select>
    </label>
    <button class="lf-btn lf-btn-primary" type="submit">Create link</button>
  </form>
</section>

<section class="lf-card">
  <h2>Open invitations</h2>
  <?php if ($invites === []): ?><p class="lf-empty">None right now.</p><?php else: ?>
  <ul class="lf-list">
    <?php foreach ($invites as $i): ?>
    <li>
      <span><b><?= $i['role'] === 'moderator' ? 'Moderator' : 'Member' ?></b> invitation, made <time datetime="<?= h(lf_iso($i['created_at'])) ?>"><?= h(lf_ago($i['created_at'])) ?></time>, expires <?= h(gmdate('M j', strtotime($i['expires_at'] . ' UTC'))) ?></span>
      <?= lf_form('manage/invites/' . (int) $i['id'] . '/revoke', $csrf, 'lf-inline', 'Cancel this invitation?') ?><button class="lf-btn lf-btn-small" type="submit">Cancel</button></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
