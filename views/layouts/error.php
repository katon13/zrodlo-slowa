<section class="error-panel">
  <p class="kicker"><?= e(t('ui.layouts.error.dostep_ograniczony')) ?></p>
  <h1><?= e($title ?? t('error.title')) ?></h1>
  <p><?= e($message ?? t('error.generic')) ?></p>
  <a class="btn-line" href="/"><?= e(t('ui.layouts.error.wroc_na_strone_gowna')) ?></a>
</section>
