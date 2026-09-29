<?php /* The house rules. Vars: $rules (one per line). */ ?>
<div class="lf-heading"><h1>House rules</h1></div>
<section class="lf-card">
  <ol class="lf-rules lf-rules-numbered"><?php foreach ($rules as $rule): ?><li><?= h($rule) ?></li><?php endforeach; ?></ol>
</section>
