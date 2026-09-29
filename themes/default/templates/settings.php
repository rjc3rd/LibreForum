<?php /* A member's own settings. Vars: $me, $error, $csrf. */ ?>
<div class="lf-heading"><h1>Your settings</h1></div>
<section class="lf-card">
  <h2>Your account</h2>
  <dl class="lf-facts">
    <dt>Username</dt><dd><?= h($me['username']) ?></dd>
    <dt>Role</dt><dd><?= $me['role'] === 'owner' ? 'Owner' : ($me['role'] === 'moderator' ? 'Moderator' : 'Member') ?></dd>
    <dt>Joined</dt><dd><time datetime="<?= h(lf_iso($me['created_at'])) ?>"><?= h(gmdate('M j, Y', strtotime($me['created_at'] . ' UTC'))) ?></time></dd>
  </dl>
</section>
<?php if ($me['host_ref'] !== null): ?>
<section class="lf-card">
  <h2>Your password</h2>
  <p>You come in through another app, so there is no password here. Sign in there to reach the forum.</p>
</section>
<?php else: ?>
<section class="lf-card">
  <h2>Change your password</h2>
  <?php if ($error): ?><p class="lf-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?= lf_form('settings', $csrf, 'lf-form lf-form-narrow') ?>
    <label>Current password<input class="lf-input" type="password" name="current" autocomplete="current-password" required></label>
    <label>New password (10 characters or more)<input class="lf-input" type="password" name="new" minlength="10" autocomplete="new-password" required></label>
    <label>Type it again<input class="lf-input" type="password" name="new2" minlength="10" autocomplete="new-password" required></label>
    <div><button class="lf-btn lf-btn-primary" type="submit">Change password</button></div>
  </form>
</section>
<?php endif; ?>
