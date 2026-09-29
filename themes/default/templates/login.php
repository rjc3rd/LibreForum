<?php /* Log in. Vars: $forum, $error, $username. */ ?>
<div class="lf-auth"><div class="lf-card">
  <span class="lf-brand"><?php lf_render('logo'); ?><span><?= h($forum) ?></span></span>
  <h1>Log in</h1>
  <form class="lf-form" method="post" action="<?= h(lf_url('login')) ?>">
    <?php if ($error): ?><p class="lf-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <label>Username<input class="lf-input" type="text" name="username" autocapitalize="none" spellcheck="false" autocomplete="username" required value="<?= h($username) ?>"></label>
    <label>Password<input class="lf-input" type="password" name="password" autocomplete="current-password" required></label>
    <button class="lf-btn lf-btn-primary" type="submit">Log in</button>
  </form>
  <p class="lf-hint">New here? You need an invitation link from the person who runs this forum.</p>
</div></div>
