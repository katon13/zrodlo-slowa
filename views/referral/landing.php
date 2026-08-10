<?php
$points = (int)($invitation['reward_points'] ?? 0);
$lang = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$formattedPoints = number_format($points, 0, ',', ' ');
$copy = static fn(string $key, array $replace = []): string => strtr(t($key, $lang), $replace);
?>
<section class="referral-landing-shell">
  <article class="referral-landing-card">
    <span class="referral-promoted-badge"><?= e(t('referral.promoted', $lang)) ?></span>
    <p class="eyebrow"><?= e(t('referral.landing.kicker', $lang)) ?></p>
    <h1><?= e($copy('referral.landing.title', ['{points}' => $formattedPoints])) ?></h1>
    <p class="referral-lead"><?= e($copy('referral.landing.lead', ['{points}' => $formattedPoints])) ?></p>
    <ol class="referral-steps">
      <li><?= e(t('referral.landing.step_install', $lang)) ?></li>
      <li><?= e(t('referral.landing.step_register', $lang)) ?></li>
      <li><?= e(t('referral.landing.step_session', $lang)) ?></li>
    </ol>
    <div class="referral-landing-actions">
      <a class="btn-red" href="<?= e((string)$app_link) ?>"><?= e(t('referral.landing.open_app', $lang)) ?></a>
      <a class="btn-outline" href="<?= e((string)$play_store_url) ?>" rel="nofollow"><?= e(t('referral.landing.install_play', $lang)) ?></a>
    </div>
    <p class="referral-expiry"><?= e($copy('referral.landing.expiry', ['{date}' => (string)($invitation['expires_at'] ?? '')])) ?></p>
  </article>
</section>
