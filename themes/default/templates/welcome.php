<?php /* Welcome screen. $mode 'invite' (someone follows an invitation: username and password) or 'name' (already
   signed in, only needs a username). Vars: $forum, $mode, $action, $error, $username, $rules, $csrf. */ ?>
<div class="lf-auth"><div class="lf-card lf-welcome">
  <span class="lf-brand"><?php lf_render('logo'); ?><span><?= h($forum) ?></span></span>
  <h1>Welcome!</h1>
  <p><?= $mode === 'invite' ? 'Choose the username other members will see, and a password to log in with.' : 'Choose the username other members will see.' ?> Your real name and email are never shown.</p>
  <?php if ($rules): ?>
  <div class="lf-rules-box">
    <h2>Before you join in</h2>
    <ul class="lf-rules"><?php foreach ($rules as $rule): ?><li><?= h($rule) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <form class="lf-form" method="post" action="<?= h($action) ?>">
    <?php if ($error): ?><p class="lf-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?php if ($mode === 'name'): ?><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><?php endif; ?>
    <label>Username<input class="lf-input" type="text" name="username" minlength="3" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9_]{2,19}" title="3 to 20 letters, digits or underscores" autocapitalize="none" spellcheck="false" autocomplete="username" required autofocus value="<?= h($username) ?>"></label>
    <p class="lf-hint">3 to 20 letters, digits or underscores. You can’t change it later.</p>
    <?php if ($mode === 'invite'): ?>
    <label>Password (10 characters or more)<input class="lf-input" type="password" name="password" minlength="10" autocomplete="new-password" required></label>
    <label>Type it again<input class="lf-input" type="password" name="password2" minlength="10" autocomplete="new-password" required></label>
    <?php endif; ?>
    <button class="lf-btn lf-btn-primary" type="submit">Join the forum</button>
  </form>
  <?php if ($mode === 'name'): ?>
  <?= lf_form('logout', $csrf, 'lf-form-quiet') ?><button class="lf-link" type="submit">Log out instead</button></form>
  <?php endif; ?>
</div></div>
