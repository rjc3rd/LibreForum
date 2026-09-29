<?php /* The forum's name and house rules. Vars: $forumName, $rulesText, $csrf. */ ?>
<section class="lf-card">
  <?= lf_form('manage/forum', $csrf, 'lf-form') ?>
    <label>Name of the forum<input class="lf-input" type="text" name="name" maxlength="60" required value="<?= h($forumName) ?>"></label>
    <label>House rules (one rule per line)
      <textarea class="lf-input lf-textarea" name="rules" rows="8" maxlength="2000"><?= h($rulesText) ?></textarea>
    </label>
    <p class="lf-hint">New members see these on their welcome screen. Everyone can read them on the House rules page.</p>
    <div><button class="lf-btn lf-btn-primary" type="submit">Save</button></div>
  </form>
</section>
