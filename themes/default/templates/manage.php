<?php /* Management pages. $section is members, invites, categories or forum; $tabs lists the ones this person may open. */ ?>
<div class="lf-heading"><h1>Manage</h1></div>
<nav class="lf-tabs" aria-label="Sections">
  <?php foreach ($tabs as $key => $name): ?><a href="<?= h(lf_url('manage/' . $key)) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= h($name) ?></a><?php endforeach; ?>
</nav>
<?php lf_render('manage-' . $section, get_defined_vars()); ?>
