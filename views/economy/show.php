<?php
$flows = $money_flows ?? [];
$policy = is_array($split_policy ?? null) ? $split_policy : [];
$lang = (string)($current_language ?? 'pl');
$tr = static fn(string $key): string => t($key, $lang);
$percent = static fn($basisPoints): string => number_format(((int)$basisPoints) / 100, ((int)$basisPoints) % 100 === 0 ? 0 : 2, ',', ' ') . '%';
$schema = strtr($tr('economy.schema.text'), [
    '{author}' => $percent($policy['author_basis_points'] ?? 4000),
    '{platform}' => $percent($policy['platform_basis_points'] ?? 4000),
    '{fund}' => $percent($policy['safety_fund_basis_points'] ?? 2000),
]);
?>
<section class="economy-hero economy-hero-editorial">
  <p class="kicker"><?= e($tr('economy.hero.kicker')) ?></p>
  <h1><?= e($tr('economy.hero.title')) ?></h1>
  <p class="lead"><?= e($tr('economy.hero.lead')) ?></p>
</section>

<?php if (is_array($talent_promotion ?? null)): ?>
<section class="economy-referral-promotion" aria-labelledby="economy-referral-title">
  <div>
    <span class="referral-promoted-badge">PROMOWANE</span>
    <p class="kicker"><?= e($tr('economy.referral.kicker')) ?></p>
    <h2 id="economy-referral-title"><?= e($tr('economy.referral.title')) ?></h2>
    <p><?= e($tr('economy.referral.description')) ?></p>
  </div>
  <div class="economy-referral-reward">
    <strong><?= number_format((int)$talent_promotion['reward_points'], 0, ',', ' ') ?> TT</strong>
    <span><?= e($tr('economy.referral.for_each')) ?></span>
  </div>
  <ol>
    <li><?= e($tr('wallet.referral.step_invite')) ?></li>
    <li><?= e($tr('wallet.referral.step_install')) ?></li>
    <li><?= e($tr('wallet.referral.step_session')) ?></li>
  </ol>
  <?php if ($thisUserId = ($_SESSION['user_id'] ?? null)): ?>
    <a class="btn-red" href="<?= e(public_language_url($lang, '/wallet#aktualne-w-talent')) ?>"><?= e($tr('economy.referral.cta')) ?></a>
  <?php else: ?>
    <a class="btn-red" href="<?= e(public_language_url($lang, '/login')) ?>"><?= e($tr('economy.referral.login_cta')) ?></a>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="economy-response-principle" id="opinie-i-polemiki" aria-labelledby="economy-response-title">
  <div>
    <span class="zs-response-principle"><?= e($tr('economy.response.badge')) ?></span>
    <p class="kicker"><?= e($tr('economy.response.kicker')) ?></p>
    <h2 id="economy-response-title"><?= e($tr('economy.response.title')) ?></h2>
    <p><?= e($tr('economy.response.description')) ?></p>
  </div>
  <ol>
    <li><strong>TT</strong><span><?= e($tr('economy.response.published')) ?></span></li>
  </ol>
</section>

<section class="revenue-principle" aria-labelledby="revenue-principle-title">
  <div class="revenue-principle-copy">
    <p class="kicker"><?= e($tr('economy.policy.kicker')) ?></p>
    <h2 id="revenue-principle-title"><?= e($tr('economy.policy.title')) ?></h2>
    <p><?= e($tr('economy.policy.lead')) ?></p>
  </div>
  <div class="revenue-split" aria-label="<?= e($tr('economy.policy.aria')) ?>">
    <article><strong><?= e($percent($policy['author_basis_points'] ?? 4000)) ?></strong><span><?= e($tr('economy.policy.author')) ?></span></article>
    <article><strong><?= e($percent($policy['platform_basis_points'] ?? 4000)) ?></strong><span><?= e($tr('economy.policy.platform')) ?></span></article>
    <article class="is-protection"><strong><?= e($percent($policy['safety_fund_basis_points'] ?? 2000)) ?></strong><span><?= e($tr('economy.policy.fund')) ?></span></article>
  </div>
  <p class="revenue-policy-note"><?= e($tr('economy.policy.note')) ?> · <?= e($tr('economy.policy.version')) ?> #<?= (int)($policy['version'] ?? 1) ?></p>
</section>

<section class="money-principle-grid">
  <article class="money-principle-card">
    <span>01</span>
    <h2><?= e($tr('economy.principle.value.title')) ?></h2>
    <p><?= e($tr('economy.principle.value.body')) ?></p>
  </article>
  <article class="money-principle-card is-red">
    <span>02</span>
    <h2><?= e($tr('economy.principle.split.title')) ?></h2>
    <p><?= e($tr('economy.principle.split.body')) ?></p>
  </article>
  <article class="money-principle-card">
    <span>03</span>
    <h2><?= e($tr('economy.principle.protection.title')) ?></h2>
    <p><?= e($tr('economy.principle.protection.body')) ?></p>
  </article>
</section>

<section class="safety-fund-story" aria-labelledby="safety-fund-story-title">
  <div class="safety-fund-story-copy">
    <p class="kicker"><?= e($tr('economy.protection.kicker')) ?></p>
    <h2 id="safety-fund-story-title"><?= e($tr('economy.protection.title')) ?></h2>
    <p class="lead"><?= e($tr('economy.protection.lead')) ?></p>
    <blockquote><?= e($tr('economy.protection.credo')) ?></blockquote>
  </div>
  <figure class="safety-fund-board">
    <img src="/assets/img/safety-fund/safety-fund-principle.png" alt="<?= e($tr('economy.protection.image_alt')) ?>" loading="lazy" decoding="async">
  </figure>
  <div class="safety-fund-purpose-grid">
    <article><strong><?= e($tr('economy.protection.legal_title')) ?></strong><p><?= e($tr('economy.protection.legal_body')) ?></p></article>
    <article><strong><?= e($tr('economy.protection.proceedings_title')) ?></strong><p><?= e($tr('economy.protection.proceedings_body')) ?></p></article>
    <article><strong><?= e($tr('economy.protection.expertise_title')) ?></strong><p><?= e($tr('economy.protection.expertise_body')) ?></p></article>
    <article><strong><?= e($tr('economy.protection.materials_title')) ?></strong><p><?= e($tr('economy.protection.materials_body')) ?></p></article>
  </div>
</section>

<section class="economy-transparency">
  <p class="kicker"><?= e($tr('economy.transparency.kicker')) ?></p>
  <h2><?= e($tr('economy.transparency.title')) ?></h2>
  <p><?= e($tr('economy.transparency.body')) ?></p>
  <div class="economy-transparency-points">
    <span><?= e($tr('economy.transparency.one_ledger')) ?></span>
    <span><?= e($tr('economy.transparency.automatic')) ?></span>
    <span><?= e($tr('economy.transparency.history')) ?></span>
    <span><?= e($tr('economy.transparency.dors3')) ?></span>
  </div>
</section>

<section class="admin-panel-block money-map-block">
  <div class="admin-section-head">
    <div>
      <p class="kicker"><?= e($tr('economy.map.kicker')) ?></p>
      <h2><?= e($tr('economy.map.title')) ?></h2>
    </div>
    <span><?= e($tr('economy.map.note')) ?></span>
  </div>
  <div class="money-flow-list">
    <?php foreach ($flows as $flow): ?>
      <article class="money-flow-row">
        <div class="money-flow-title">
          <strong><?= e((string)($flow['label'] ?? '')) ?></strong>
          <small><?= e((string)($flow['note'] ?? '')) ?></small>
        </div>
        <div><span><?= e($tr('economy.map.payer')) ?></span><b><?= e((string)($flow['payer'] ?? '')) ?></b></div>
        <div><span><?= e($tr('economy.map.action')) ?></span><b><?= e((string)($flow['action'] ?? '')) ?></b></div>
        <div><span><?= e($tr('economy.map.receiver')) ?></span><b><?= e((string)($flow['receiver'] ?? '')) ?></b></div>
        <div>
          <span><?= e($tr('economy.map.record')) ?></span>
          <?php if (isset($flow['wallet']) && is_array($flow['wallet'])): ?>
            <?php foreach ($flow['wallet'] as $wVal): ?><code><?= e((string)$wVal) ?></code><?php endforeach; ?>
          <?php else: ?>
            <code><?= e((string)($flow['wallet'] ?? '')) ?></code>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="premium-strip premium-strip-wide">
  <div><strong><?= e($tr('economy.schema.label')) ?></strong><br><?= e($schema) ?></div>
  <a class="read-more" href="<?= e(public_language_url($lang, '/register')) ?>"><?= e($tr('economy.cta')) ?> <span>→</span></a>
</section>
