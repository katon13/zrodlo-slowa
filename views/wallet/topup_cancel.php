<section class="admin-page-head">
  <p class="kicker"><?= t('wallet.topup.kicker') ?></p>
  <h1><?= t('wallet.topup.cancel.title') ?></h1>
  <p><?= t('wallet.topup.cancel.desc') ?></p>
</section>

<section class="admin-panel-block zs-topup-result-panel">
  <div class="zs-icon-status is-error"><?= zs_icon('x-circle') ?></div>
  <h2><?= t('wallet.topup.cancel.h2') ?></h2>
  <p><?= t('wallet.topup.cancel.info') ?></p>
  
  <div class="zs-result-actions">
    <a class="zs-btn-red" href="<?= e(public_language_url(public_language(), '/wallet/topup')) ?>"><?= t('wallet.topup.cancel.btn_retry') ?></a>
    <a class="text-link" href="<?= e(public_language_url(public_language(), '/wallet')) ?>"><?= t('wallet.history_btn') ?></a>
  </div>
</section>
