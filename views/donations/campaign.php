<?php $money = fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . t('ui.donations.campaign.z'); ?>
<?php if ($campaign): ?>
<section class="admin-page-head">
  <p class="kicker"><?= e(t('ui.donations.campaign.wspolne_zrodo')) ?></p>
  <h1><?= e($campaign['name']) ?></h1>
  <p><?= nl2br(e($campaign['description'])) ?></p>
</section>

<section class="admin-panel-block">
  <div class="donation-progress" aria-label="<?= e(t('ui.donations.campaign.postep_zbiorki')) ?>">
    <progress value="<?= max(0, min(100, (int)$campaign['progress_percent'])) ?>" max="100"><?= (int)$campaign['progress_percent'] ?>%</progress>
    <strong><?= (int)$campaign['progress_percent'] ?>%</strong>
  </div>
  <p><?= e(t('ui.donations.campaign.zebrano')) ?> <strong><?= $money($campaign['current_amount_minor']) ?></strong> z <?= $money($campaign['target_amount_minor']) ?></p>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div>
      <span><?= e(t('ui.donations.campaign.wsparcie')) ?></span>
      <h2><?= e(t('ui.donations.campaign.wesprzyj_kampanie')) ?></h2>
    </div>
  </div>
  <form method="post" action="/donations/manual" class="form-grid two">
    <?= csrf_field() ?>
    <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
    <label class="field"><span><?= e(t('ui.donations.campaign.email_opcjonalnie')) ?></span><input type="email" name="email"></label>
    <label class="field"><span><?= e(t('article.support.amount_minor')) ?></span><input type="number" name="amount_minor" min="100" value="1000"></label>
    <label class="field full"><span><?= e(t('article.support.form_note')) ?></span><textarea name="note" rows="3"></textarea></label>
    <button class="btn-red" type="submit"><?= e(t('ui.donations.campaign.wpac_zarejestruj_wpate')) ?></button>
  </form>
</section>
<?php else: ?>
<section class="error-panel">
  <p class="kicker"><?= e(t('ui.donations.campaign.nie_znaleziono')) ?></p>
  <h1><?= e(t('ui.donations.campaign.nie_znaleziono_kampanii')) ?></h1>
  <p><?= e(t('ui.donations.campaign.wybrana_kampania_darowizn_nie_istnieje_lub_zostaa_zakonczona')) ?></p>
  <a class="btn-red" href="/"><?= e(t('ui.donations.campaign.powrot_do_strony_gownej')) ?></a>
</section>
<?php endif; ?>
