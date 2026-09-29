<?php /* Something went wrong or isn't there. Vars: $heading, $message. */ ?>
<div class="lf-auth"><div class="lf-card lf-center">
  <h1><?= h($heading) ?></h1>
  <p><?= h($message) ?></p>
  <p><a class="lf-btn" href="<?= h(lf_url()) ?>">Back to the forum</a></p>
</div></div>
