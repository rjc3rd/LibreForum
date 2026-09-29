<?php /* First run: name the forum and create the owner. Vars: $error, $values (forum, username). */ ?>
<div class="lf-auth"><div class="lf-card">
  <span class="lf-brand"><?php lf_render('logo'); ?><span>LibreForum</span></span>
  <h1>Set up your forum</h1>
  <p>This is the first visit, so you are the owner. Choose a name for the forum and the username and password you will log in with. This page only appears once.</p>
  <form class="lf-form" method="post" action="<?= h(lf_url('setup')) ?>">
    <?php if ($error): ?><p class="lf-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <label>Name of the forum<input class="lf-input" type="text" name="forum" maxlength="60" required value="<?= h($values['forum']) ?>"></label>
    <label>Your username<input class="lf-input" type="text" name="username" minlength="3" maxlength="20" autocapitalize="none" spellcheck="false" autocomplete="username" required value="<?= h($values['username']) ?>"></label>
    <label>Password (10 characters or more)<input class="lf-input" type="password" name="password" minlength="10" autocomplete="new-password" required></label>
    <label>Type it again<input class="lf-input" type="password" name="password2" minlength="10" autocomplete="new-password" required></label>
    <button class="lf-btn lf-btn-primary" type="submit">Create the forum</button>
  </form>
</div></div>
