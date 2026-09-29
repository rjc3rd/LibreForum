<?php /* Categories: rename, say who may start threads, reorder, delete, add. Vars: $categories, $csrf. */ ?>
<?php foreach ($categories as $i => $c): $cid = (int) $c['id']; ?>
<section class="lf-card lf-category">
  <?= lf_form("manage/categories/$cid/save", $csrf, 'lf-form') ?>
    <label>Name<input class="lf-input" type="text" name="name" maxlength="60" required value="<?= h($c['name']) ?>"></label>
    <label>Description<input class="lf-input" type="text" name="description" maxlength="200" value="<?= h($c['description']) ?>"></label>
    <label class="lf-check"><input type="checkbox" name="staff_only" value="1"<?= $c['staff_only'] ? ' checked' : '' ?>> Only moderators can start threads here (everyone can reply)</label>
    <div><button class="lf-btn lf-btn-small lf-btn-primary" type="submit">Save</button></div>
  </form>
  <div class="lf-category-tools">
    <span class="lf-hint"><?= (int) $c['thread_count'] ?> <?= (int) $c['thread_count'] === 1 ? 'thread' : 'threads' ?> · address <code>/c/<?= h($c['slug']) ?></code></span>
    <span class="lf-grow"></span>
    <?php if ($i > 0): ?><?= lf_form("manage/categories/$cid/up", $csrf, 'lf-inline') ?><button class="lf-btn lf-btn-small" type="submit" title="Move up"><?= lf_icon('up') ?> <span class="lf-sr">Move up</span></button></form><?php endif; ?>
    <?php if ($i < count($categories) - 1): ?><?= lf_form("manage/categories/$cid/down", $csrf, 'lf-inline') ?><button class="lf-btn lf-btn-small" type="submit" title="Move down"><?= lf_icon('down') ?> <span class="lf-sr">Move down</span></button></form><?php endif; ?>
    <?= lf_form("manage/categories/$cid/delete", $csrf, 'lf-inline', 'Delete this category?') ?><button class="lf-btn lf-btn-small lf-btn-danger" type="submit"><?= lf_icon('trash') ?> Delete</button></form>
  </div>
</section>
<?php endforeach; ?>

<section class="lf-card">
  <h2>Add a category</h2>
  <?= lf_form('manage/categories', $csrf, 'lf-form') ?>
    <label>Name<input class="lf-input" type="text" name="name" maxlength="60" required></label>
    <label>Description<input class="lf-input" type="text" name="description" maxlength="200"></label>
    <label class="lf-check"><input type="checkbox" name="staff_only" value="1"> Only moderators can start threads here (everyone can reply)</label>
    <div><button class="lf-btn lf-btn-primary" type="submit">Add category</button></div>
  </form>
</section>
