<?php /* Start a thread. Vars: $categories, $selected (category id), $values (title, body), $error, $csrf. */ ?>
<div class="lf-heading"><h1>New thread</h1></div>
<section class="lf-card lf-compose">
  <?php if ($error): ?><p class="lf-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?= lf_form('new', $csrf, 'lf-form') ?>
    <label>Category
      <select class="lf-select" name="category" required>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $selected ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Title<input class="lf-input" type="text" name="title" maxlength="150" required autofocus value="<?= h($values['title']) ?>"></label>
    <label>Message<textarea class="lf-input lf-textarea" name="body" rows="9" maxlength="10000" required><?= h($values['body']) ?></textarea></label>
    <p class="lf-hint">A blank line starts a new paragraph. Put `backticks` around code, or ``` on a line of its own above and below a block. Web addresses become links.</p>
    <div class="lf-buttons"><button class="lf-btn lf-btn-primary" type="submit">Post thread</button> <a class="lf-btn" href="<?= h(lf_url()) ?>">Cancel</a></div>
  </form>
</section>
