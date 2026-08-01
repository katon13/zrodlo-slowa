<section class="admin-page-head">
  <p class="kicker"><?= t('wallet.topup.kicker') ?></p>
  <h1><?= t('wallet.topup.success.title') ?></h1>
  <p><?= t('wallet.topup.success.desc') ?></p>
</section>

<section class="admin-panel-block zs-topup-result-panel">
  <div class="zs-icon-status is-success"><?= zs_icon('check-circle') ?></div>
  <h2><?= t('wallet.topup.success.h2') ?></h2>
  <p><?= t('wallet.topup.success.info') ?></p>
  
  <div class="zs-result-actions">
    <a class="zs-btn-red" href="<?= e(public_language_url(public_language(), '/wallet')) ?>"><?= t('wallet.history_btn') ?></a>
    <a class="text-link" href="<?= e(public_language_url(public_language(), '/wallet/topup')) ?>"><?= t('wallet.topup.btn_history') ?></a>
  </div>
</section>
