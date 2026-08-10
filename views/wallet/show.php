<?php 
use App\Services\ActivityUiHelper;
use App\Services\CurrencyRateService;

$currencyService = new CurrencyRateService();
$lang = strtolower((string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl')));
$userDisplayCurrency = $permissions['display_currency'] ?? $_SESSION['display_currency'] ?? 'AUTO';
$currency = $currencyService->effectiveCurrency($lang, $userDisplayCurrency);
$localApprox = $currencyService->ttToLocalApprox(10, $lang, $userDisplayCurrency);

$displayAmount = function($minor) use ($currencyService, $lang, $currency) {
    $amountPln = ((int)$minor) / 100;
    
    // Dla kwoty 0 zwracamy po prostu 0 w danej walucie bez przeliczania (nie ma sensu sprawdzać NBP)
    if ($amountPln == 0) {
        return $currencyService->formatSimple(0, $currency, $lang);
    }

    if ($currency === 'PLN') {
        return $currencyService->formatSimple($amountPln, 'PLN', $lang);
    }
    
    $val = $currencyService->convertPlnToCurrency($amountPln, $currency);
    if ($val === null) {
        return $currencyService->formatSimple($amountPln, 'PLN', $lang);
    }
    
    return $currencyService->formatSimple($val, $currency, $lang);
};
$money = $displayAmount;

$isActivity = static fn($type) => in_array($type, [
    'login_bonus', 'registration_bonus', 'day_visit_bonus', 'article_read_bonus', 
    'response_publication_bonus', 'poll_answer_bonus', 'share_bonus', 'link_click_bonus',
    'like_bonus', 'survey_reward', 'ad_watch_bonus', 'ad_click_reward', 
    'newsletter_open_reward', 'ppv_reward', 'live_event_reward', 'manual_reward', 'app_referral_bonus'
]);
?>
<section class="admin-page-head" data-wallet-lang="<?= e($lang) ?>" data-detected="<?= e(public_language()) ?>">
  <p class="kicker"><?= t('wallet.kicker', $lang) ?></p>
  <h1><?= t('wallet.title', $lang) ?></h1>
  <p><?= t('wallet.not_active_desc', $lang) ?></p>
</section>

<?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>

<section class="author-permissions-strip">
  <span class="<?= !empty($permissions['talent_enabled']) ? 'is-on' : 'is-off' ?>"><?= t('author.dashboard.permission_talent', $lang) ?></span>
  <span class="<?= !empty($permissions['wallet_enabled']) ? 'is-on' : 'is-off' ?>"><?= t('author.dashboard.permission_wallet', $lang) ?></span>
  <span class="<?= !empty($permissions['payout_enabled']) ? 'is-on' : 'is-off' ?>"><?= t('author.dashboard.permission_payout', $lang) ?></span>
</section>

<?php if (empty($permissions['wallet_enabled'])): ?>
  <section class="empty-state">
    <h3><?= t('wallet.not_active_title', $lang) ?></h3>
    <p><?= t('wallet.not_active_desc', $lang) ?></p>
  </section>
<?php else: ?>
  <section class="wallet-financial-overview" aria-label="<?= e(t('wallet.overview_aria_label', $lang)) ?>">
    <div class="wallet-balance-container">
      <article class="talent-balance-panel">
        <div class="talent-balance-head">
          <span class="talent-mark">Ź</span>
          <strong><?= mb_strtoupper(t('brand.name', $lang), 'UTF-8') ?></strong>
        </div>
        <div class="talent-balance-body">
          <div>
            <p class="kicker"><?= t('wallet.available_funds', $lang) ?></p>
            <div class="talent-balance-value"><span><?= number_format((int)($wallet['points_balance'] ?? 0), 0, ',', ' ') ?></span><em>TT</em></div>
            <p class="talent-balance-note"><?= t('wallet.talent_wallet', $lang) ?></p>
          </div>
          <div class="talent-token" aria-hidden="true">
            <span class="talent-token-ring"></span>
            <span class="talent-token-core"><b>1</b><small><?= mb_strtoupper('TT', 'UTF-8') ?></small></span>
          </div>
        </div>
        <div class="talent-balance-foot">
          <span><i>✦</i> <?= ($currency === 'PLN' ? t('wallet.rate.base', $lang) . ': ' : '') ?>10 TT = <?= e($localApprox ?: '1,0 PLN') ?></span>
          <a href="#historia-portfela" class="zs-btn-text"><?= t('wallet.history_btn', $lang) ?></a>
        </div>
      </article>

      <div class="wallet-funds-panel">
        <div class="settlement-card">
          <span><?= t('wallet.total_balance', $lang) ?></span>
          <strong><?= $displayAmount($wallet['available_minor'] ?? 0) ?></strong>
          <small><?= t('wallet.pln_wallet', $lang) ?></small>
        </div>
        <div class="settlement-card">
          <span><?= mb_strtoupper(t('brand.name', $lang), 'UTF-8') ?></span>
          <strong><?= $displayAmount($wallet['slowo_available_minor'] ?? 0) ?></strong>
          <small><?= t('wallet.from_tt_conversion', $lang) ?></small>
        </div>
        <div class="settlement-card">
          <span><?= mb_strtoupper(t('layout.menu.latest', $lang), 'UTF-8') ?></span>
          <strong><?= $displayAmount($wallet['main_available_minor'] ?? 0) ?></strong>
          <small><?= t('wallet.from_topups', $lang) ?></small>
        </div>
        <div class="settlement-card is-red">
          <span><?= t('wallet.reserved', $lang) ?></span>
          <strong><?= $displayAmount($wallet['reserved_minor'] ?? 0) ?></strong>
          <small><?= t('wallet.pending_payouts', $lang) ?></small>
        </div>
      </div>
    </div>
    
    <div class="wallet-cta-group">
      <a href="<?= e(public_language_url($lang, '/wallet/topup')) ?>" class="zs-btn-red is-large"><?= zs_icon('plus-circle') ?> <?= t('wallet.topup_btn', $lang) ?></a>
      <a href="#funds-conversion" class="zs-btn-outline is-large"><?= zs_icon('refresh') ?> <?= t('wallet.convert_tt_btn', $lang) ?></a>
    </div>

    <div class="wallet-relation-info">
      <h3><?= t('wallet.how_it_works_title', $lang) ?></h3>
      <div class="wallet-relation-grid">
        <div class="wallet-relation-item">
          <strong><?= t('wallet.talent_wallet', $lang) ?></strong>
          <p><?= t('wallet.talent_description', $lang) ?></p>
        </div>
        <div class="wallet-relation-item">
          <strong><?= t('wallet.pln_wallet', $lang) ?></strong>
          <p><?= t('wallet.pln_description', $lang) ?></p>
        </div>
        <div class="wallet-relation-item">
          <strong><?= t('wallet.how_it_works.rate_title', $lang) ?></strong>
          <p>
            <?php if ($localApprox): ?>
              10 TT = <?= e($localApprox) ?>.<br>
              <?php if ($currency !== 'PLN'): ?>
                <small style="opacity: 0.8;"><?= t('wallet.rate.nbp_source', $lang) ?></small>
              <?php endif; ?>
            <?php else: ?>
          <?= e($tt_rate_label ?? t('wallet.rate.default_label', $lang)) ?>.
            <?php endif; ?>
          </p>
          <p><?= t('wallet.conversion_description', $lang) ?></p>
        </div>
      </div>
    </div>
  </section>

  <section id="funds-conversion" class="admin-panel-block zs-wallet-actions-center">
    <div class="admin-section-head">
      <div>
        <p class="kicker"><?= t('wallet.funds_center.subtitle', $lang) ?></p>
        <h2><?= t('wallet.conversion.title', $lang) ?></h2>
      </div>
    </div>
    <div class="zs-patch4-grid">
      <article class="zs-patch4-card">
        <div class="money-home-icon"><?= zs_icon('wallet') ?></div>
        <span><?= t('wallet.pln_wallet', $lang) ?></span>
        <strong><?= t('wallet.actions.top_up_pln', $lang) ?></strong>
        <p class="zs-card-desc"><?= t('wallet.topup_desc', $lang) ?></p>
        <div class="zs-card-action">
          <a class="zs-btn-red" href="<?= e(public_language_url($lang, '/wallet/topup')) ?>"><?= zs_icon('plus-circle') ?> <?= t('wallet.actions.top_up_pln', $lang) ?></a>
        </div>
      </article>
      <article class="zs-patch4-card">
        <div class="money-home-icon"><?= zs_icon('refresh') ?></div>
        <span><?= t('wallet.actions.cash_out_tt', $lang) ?></span>
        <strong><?= t('wallet.actions.transfer_tt_to_pln', $lang) ?></strong>
        <p class="zs-card-desc">
          <?php if ($localApprox): ?>
            10 TT = <?= e($localApprox) ?>.
          <?php else: ?>
            <?= e($tt_rate_label ?? t('wallet.rate.default_label', $lang)) ?>.
          <?php endif; ?>
          <?= t('wallet.how_it_works.talent_description_full', $lang) ?>
        </p>
        <div class="zs-transfer-stats">
          <small><?= t('wallet.conversion.rate', $lang) ?>: <?= e($localApprox ?: ($tt_rate_label ?? t('wallet.rate.default_label', $lang))) ?></small>
          <small><?= t('wallet.conversion.fee', $lang) ?>: <?= (int)($transferQuote['fee_percent'] ?? 5) ?>%</small>
          <small><?= t('wallet.conversion.control_active', $lang) ?></small>
        </div>
        <form method="post" action="<?= e(public_language_url($lang, '/wallet/transfer/talent-to-pln')) ?>" class="zs-inline-transfer-form">
          <?= csrf_field() ?>
          <div class="zs-input-group">
            <input type="number" name="talent_amount" min="10" step="10" value="100" aria-label="<?= t('wallet.actions.cash_out_tt', $lang) ?>">
            <button class="zs-btn-red" type="submit"><?= t('wallet.actions.transfer_btn', $lang) ?></button>
          </div>
        </form>
      </article>
    </div>
  </section>

  <?php if (empty($permissions['payout_enabled'])): ?>
    <section class="pending-author-notice">
      <p class="kicker"><?= t('wallet.withdrawals.inactive_title', $lang) ?></p>
      <h2><?= t('wallet.withdrawals.inactive_title', $lang) ?></h2>
      <p><?= t('wallet.withdrawals.inactive_desc', $lang) ?></p>
    </section>
  <?php else: ?>
    <section class="admin-panel-block zs-payout-page">
      <div class="admin-section-head">
        <div>
          <p class="kicker"><?= t('wallet.payout_active', $lang) ?></p>
          <h2><?= t('wallet.withdrawals.new_request', $lang) ?></h2>
        </div>
        <span class="zs-badge-info"><?= e(str_replace('{amount}', $displayAmount(1000), t('wallet.withdrawals.minimum_dynamic', $lang))) ?></span>
      </div>

      <div class="zs-payout-grid">
        <div class="zs-payout-card">
          <div class="zs-card-head">
            <h3><?= t('wallet.withdrawals.methods_title', $lang) ?></h3>
          </div>
          <div class="zs-card-body">
            <?php if (empty($methods)): ?>
              <div class="zs-empty-state-mini">
                <p><?= t('wallet.withdrawals.no_method', $lang) ?></p>
                <small><?= t('wallet.withdrawals.no_method_description', $lang) ?></small>
              </div>
            <?php endif; ?>
            
            <div class="zs-method-list">
              <?php foreach ($methods as $m): ?>
                <div class="zs-method-item">
                  <div class="zs-method-info">
                    <strong><?= e($m['label']) ?></strong>
                    <span><?= e($m['type']) ?> · <?= e($m['account_ref']) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <form class="zs-payout-form" method="post" action="<?= e(public_language_url($lang, '/wallet/payout-methods')) ?>">
              <?= csrf_field() ?>
              <div class="zs-form-row">
                <label class="zs-field">
                  <span><?= t('wallet.withdrawals.method_type', $lang) ?></span>
                  <select name="type">
                    <option value="bank"><?= t('wallet.withdrawals.type.bank', $lang) ?></option>
                    <option value="blik"><?= t('wallet.withdrawals.type.blik', $lang) ?></option>
                    <option value="paypal"><?= t('wallet.withdrawals.type.paypal', $lang) ?></option>
                    <option value="manual"><?= t('wallet.withdrawals.type.manual', $lang) ?></option>
                  </select>
                </label>
              </div>
              <div class="zs-form-row">
                <label class="zs-field">
                  <span><?= t('wallet.withdrawals.method_label', $lang) ?></span>
                  <input name="label" required placeholder="<?= t('wallet.withdrawals.method_placeholder', $lang) ?>">
                </label>
              </div>
              <div class="zs-form-row">
                <label class="zs-field">
                  <span><?= t('wallet.withdrawals.transfer_details', $lang) ?></span>
                  <input name="account_ref" required placeholder="<?= t('wallet.withdrawals.details_placeholder', $lang) ?>">
                </label>
              </div>
              <div class="zs-form-actions">
                <button class="zs-btn-red" type="submit"><?= t('wallet.withdrawals.add_method', $lang) ?></button>
              </div>
            </form>
          </div>
        </div>

        <div class="zs-payout-card">
          <div class="zs-card-head">
            <h3><?= t('wallet.withdrawals.request_title', $lang) ?></h3>
          </div>
          <div class="zs-card-body">
            <form class="zs-payout-form" method="post" action="<?= e(public_language_url($lang, '/wallet/payout-request')) ?>">
              <?= csrf_field() ?>
              <div class="zs-form-row">
                <label class="zs-field">
                  <span><?= t('wallet.withdrawals.choose_method', $lang) ?></span>
                  <select name="payout_method_id" required>
                    <option value="" disabled selected><?= t('wallet.withdrawals.choose_placeholder', $lang) ?></option>
                    <?php foreach ($methods as $m): ?>
                      <option value="<?= (int)$m['id'] ?>"><?= e($m['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div class="zs-form-row">
                <label class="zs-field">
                  <span><?= t('wallet.withdrawals.amount_minor', $lang) ?></span>
                  <input type="number" name="amount_minor" min="1000" required placeholder="1000">
                  <span class="zs-field-help"><?= e(str_replace('{amount}', $displayAmount(1000), t('wallet.withdrawals.amount_help_dynamic', $lang))) ?></span>
                </label>
              </div>
              <div class="zs-form-row">
                <label class="zs-field">
                  <span><?= t('wallet.withdrawals.accounting_note', $lang) ?></span>
                  <textarea name="note" placeholder="<?= t('wallet.withdrawals.note_placeholder', $lang) ?>" rows="2"></textarea>
                </label>
              </div>
              <div class="zs-form-actions">
                <button class="zs-btn-red" type="submit"><?= t('wallet.withdrawals.submit_request', $lang) ?></button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <section class="admin-panel-block zs-payout-history">
    <div class="admin-section-head">
      <div>
        <p class="kicker"><?= t('wallet.history.title', $lang) ?></p>
        <h2><?= t('wallet.history.subtitle', $lang) ?></h2>
      </div>
      <span class="zs-badge-count"><?= count($payouts ?? []) ?> <?= t('wallet.history.count_label', $lang) ?></span>
    </div>
    
    <?php if (empty($payouts)): ?>
      <div class="zs-empty-state">
        <h3><?= t('wallet.history.no_withdrawals', $lang) ?></h3>
        <p><?= t('wallet.history.no_withdrawals_description', $lang) ?></p>
      </div>
    <?php else: ?>
      <div class="zs-admin-table-wrapper">
        <table class="zs-admin-table">
          <thead>
            <tr>
              <th><?= t('wallet.history.table.id', $lang) ?></th>
              <th><?= t('wallet.history.table.date', $lang) ?></th>
              <th><?= t('wallet.history.table.method', $lang) ?></th>
              <th><?= t('wallet.history.table.status', $lang) ?></th>
              <th class="text-right"><?= t('wallet.history.table.amount', $lang) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payouts as $p): ?>
              <?php $s = $payoutStatusMap[$p['status']] ?? ['label' => $p['status'], 'class' => 'pending']; ?>
              <tr>
                <td class="zs-id-cell">#<?= (int)$p['id'] ?></td>
                <td><?= date('d.m.Y H:i', strtotime($p['requested_at'])) ?></td>
                <td><?= e($p['method_label'] ?: t('wallet.history.no_method_label', $lang)) ?></td>
                <td><span class="zs-status-badge is-<?= e($s['class']) ?>"><?= e($s['label']) ?></span></td>
                <td class="text-right zs-amount-cell"><strong><?= $money($p['amount_minor']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>



  <section class="money-home-section wallet-money-map">
    <div class="admin-section-head">
      <div><p class="kicker"><?= t('wallet.earning.title', $lang) ?></p><h2><?= t('wallet.earning.subtitle', $lang) ?></h2></div>
      <a class="text-link" href="<?= e(public_language_url($lang, '/jak-zarabiac')) ?>"><?= t('wallet.earning.full_schema', $lang) ?></a>
    </div>
    <div class="money-home-grid">
      <article class="money-home-card">
        <div class="money-home-icon"><?= zs_icon('article') ?></div>
        <span><?= t('wallet.earning.texts_title', $lang) ?></span>
        <strong><?= t('wallet.earning.texts_percent', $lang) ?></strong>
        <small><?= t('wallet.earning.texts_description', $lang) ?></small>
      </article>
      <article class="money-home-card">
        <div class="money-home-icon"><?= zs_icon('sun') ?></div>
        <span><?= t('wallet.earning.activity_title', $lang) ?></span>
        <strong><?= t('wallet.earning.activity_badge', $lang) ?></strong>
        <small><?= t('wallet.earning.activity_description', $lang) ?></small>
      </article>
      <article class="money-home-card">
        <div class="money-home-icon"><?= zs_icon('survey') ?></div>
        <span><?= t('wallet.earning.polls_title', $lang) ?></span>
        <strong><?= t('wallet.earning.polls_badge', $lang) ?></strong>
        <small><?= t('wallet.earning.polls_description', $lang) ?></small>
      </article>
      <article class="money-home-card">
        <div class="money-home-icon"><?= zs_icon('eye') ?></div>
        <span><?= t('wallet.earning.ads_title', $lang) ?></span>
        <strong><?= t('wallet.earning.ads_badge', $lang) ?></strong>
        <small><?= t('wallet.earning.ads_description', $lang) ?></small>
      </article>
    </div>
  </section>

  <section class="latest">
    <div class="admin-section-head">
      <div><p class="kicker"><?= t('wallet.activity.title', $lang) ?></p><h2><?= t('wallet.activity.subtitle', $lang) ?></h2></div>
    </div>
    <?php if (empty($bonus_notifications)): ?>
      <div class="zs-empty-state-mini"><p><?= t('wallet.activity.no_bonuses', $lang) ?></p></div>
    <?php else: ?>
      <div class="zs-latest-earnings">
        <?php foreach (array_slice($bonus_notifications, 0, 3) as $bonus): ?>
          <?php $bonusUi = ActivityUiHelper::resolveRow($bonus, $lang); ?>
          <div class="zs-latest-card">
            <div class="zs-ledger-icon">
              <?= ActivityUiHelper::renderIcon((string)$bonus['activity_type']) ?>
            </div>
            <div class="zs-ledger-info">
              <div class="zs-ledger-title"><?= e($bonusUi['title']) ?></div>
              <?php if ($isActivity($bonus['activity_type']) && (int)$bonus['points_amount'] > 0): ?>
                  <div class="zs-ledger-amount is-positive">+<?= (int)$bonus['points_amount'] ?> TT</div>
                  <div class="zs-ledger-meta"><?= date('d.m.Y', strtotime($bonus['created_at'])) ?></div>
              <?php else: ?>
                  <div class="zs-ledger-amount is-positive"><?= ($bonus['amount_minor'] > 0 ? '+' : '') . $money($bonus['amount_minor'] ?? 0) ?></div>
                  <div class="zs-ledger-meta">
                    <?php if ((int)$bonus['points_amount'] > 0): ?>
                       <span class="zs-ledger-points">+<?= (int)$bonus['points_amount'] ?> TT</span> · 
                    <?php endif; ?>
                    <?= date('d.m.Y', strtotime($bonus['created_at'])) ?>
                  </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="latest" id="historia-portfela">
    <div class="admin-section-head">
      <div><p class="kicker"><?= t('wallet.ledger.title', $lang) ?></p><h2><?= t('wallet.ledger.subtitle', $lang) ?></h2></div>
    </div>
    <div class="zs-ledger-list">
    <?php foreach ($transactions as $t): ?>
      <?php 
        $activityUi = ActivityUiHelper::resolveRow($t, $lang);
        $humanType = $activityUi['title'];
        $amountMinor = (int)$t['amount_minor'];
        $isZero = $amountMinor === 0;
        $cleanDesc = $activityUi['description'];
      ?>
      <article class="zs-ledger-row">
        <div class="zs-ledger-main">
          <div class="zs-ledger-icon">
             <?= ActivityUiHelper::renderIcon((string)$t['type']) ?>
          </div>
          <div class="zs-ledger-info">
              <div class="zs-ledger-title"><?= e($humanType) ?></div>
              <div class="zs-ledger-desc"><?= e($cleanDesc) ?></div>
          </div>
        </div>
        <div class="zs-ledger-side">
           <?php if ($isActivity($t['type']) && (int)$t['points'] > 0): ?>
               <div class="zs-ledger-amount is-positive">+<?= (int)$t['points'] ?> TT</div>
               <div class="zs-ledger-meta"><?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></div>
           <?php else: ?>
               <div class="zs-ledger-amount <?= $amountMinor > 0 ? 'is-positive' : '' ?>">
                  <?= ($amountMinor > 0 ? '+' : '') . $money($amountMinor) ?>
               </div>
               <div class="zs-ledger-meta">
                  <?php if ((int)$t['points'] !== 0): ?>
                      <span class="zs-ledger-points"><?= ((int)$t['points'] > 0 ? '+' : '') . (int)$t['points'] ?> TT</span>
                      <span>·</span>
                  <?php endif; ?>
                  <?= date('d.m.Y H:i', strtotime($t['created_at'])) ?>
               </div>
           <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </section>

  <section class="wallet-referral-panel" id="aktualne-w-talent" data-referral-widget hidden>
    <div class="admin-section-head">
      <div>
        <p class="kicker"><?= t('wallet.referral.kicker', $lang) ?></p>
        <h2><?= t('wallet.referral.title', $lang) ?></h2>
      </div>
      <span class="referral-promoted-badge" data-referral-badge hidden><?= e(t('referral.promoted', $lang)) ?></span>
    </div>
    <div class="wallet-referral-loading" data-referral-loading><?= t('wallet.referral.loading', $lang) ?></div>
    <div data-referral-content hidden>
      <p class="wallet-referral-description" data-referral-description></p>
      <div class="wallet-referral-stats">
        <article><strong data-referral-reward>—</strong><span><?= t('wallet.referral.reward_each', $lang) ?></span></article>
        <article><strong data-referral-active>—</strong><span><?= t('wallet.referral.active', $lang) ?></span></article>
        <article><strong data-referral-success>—</strong><span><?= t('wallet.referral.successful', $lang) ?></span></article>
      </div>
      <ol class="wallet-referral-steps">
        <li><?= t('wallet.referral.step_invite', $lang) ?></li>
        <li><?= t('wallet.referral.step_install', $lang) ?></li>
        <li><?= t('wallet.referral.step_session', $lang) ?></li>
      </ol>
      <form class="wallet-referral-form" data-referral-form>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label><span><?= t('wallet.referral.email_label', $lang) ?></span><input type="email" name="email" maxlength="190" required autocomplete="email" placeholder="<?= e(t('wallet.referral.email_placeholder', $lang)) ?>"></label>
        <button type="submit" class="zs-btn-red"><?= t('wallet.referral.send', $lang) ?></button>
      </form>
      <p class="wallet-referral-message" data-referral-message role="status"></p>
      <div class="wallet-referral-list" data-referral-list></div>
    </div>
  </section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function() {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerText = '<?= e(t('wallet.wait_js', $lang)) ?>';
      }
    });
  });

  var widget = document.querySelector('[data-referral-widget]');
  if (!widget) return;
  var loading = widget.querySelector('[data-referral-loading]');
  var content = widget.querySelector('[data-referral-content]');
  var form = widget.querySelector('[data-referral-form]');
  var message = widget.querySelector('[data-referral-message]');
  var statusLabels = <?= json_encode([
      'mail_queued' => t('wallet.referral.status.mail_queued', $lang),
      'sent' => t('wallet.referral.status.sent', $lang),
      'link_opened' => t('wallet.referral.status.link_opened', $lang),
      'installed' => t('wallet.referral.status.installed', $lang),
      'registered' => t('wallet.referral.status.registered', $lang),
      'reward_queued' => t('wallet.referral.status.reward_queued', $lang),
      'rewarded' => t('wallet.referral.status.rewarded', $lang),
      'mail_dead_letter' => t('wallet.referral.status.mail_dead_letter', $lang),
      'expired' => t('wallet.referral.status.expired', $lang),
      'cancelled' => t('wallet.referral.status.cancelled', $lang),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function renderReferral(data) {
    if (!data.promotion || data.pool_exhausted) {
      widget.hidden = true;
      return;
    }
    widget.hidden = false;
    loading.hidden = true;
    content.hidden = false;
    var promotion = data.promotion;
    widget.querySelector('[data-referral-badge]').hidden = !promotion;
    widget.querySelector('[data-referral-description]').textContent = promotion
      ? '<?= e(t('wallet.referral.description', $lang)) ?>'
      : '<?= e(t('wallet.referral.inactive', $lang)) ?>';
    widget.querySelector('[data-referral-reward]').textContent = promotion ? promotion.reward_points.toLocaleString() + ' TT' : '—';
    widget.querySelector('[data-referral-active]').textContent = data.active_count + ' / ' + data.active_limit;
    widget.querySelector('[data-referral-success]').textContent = data.successful_count + ' / ' + data.successful_limit;
    form.hidden = !data.can_invite;
    var list = widget.querySelector('[data-referral-list]');
    list.replaceChildren();
    if (!data.invitations.length) {
      var empty = document.createElement('p');
      empty.className = 'wallet-referral-empty';
      empty.textContent = '<?= e(t('wallet.referral.no_invitations', $lang)) ?>';
      list.appendChild(empty);
      return;
    }
    data.invitations.forEach(function(invitation) {
      var row = document.createElement('article');
      row.className = 'wallet-referral-row';
      var copy = document.createElement('div');
      var email = document.createElement('strong');
      email.textContent = invitation.invited_email;
      var meta = document.createElement('small');
      meta.textContent = invitation.reward_points.toLocaleString() + ' TT · ' + invitation.created_at;
      copy.append(email, meta);
      var state = document.createElement('span');
      state.className = 'zs-status-badge is-' + invitation.status.replace(/_/g, '-');
      state.textContent = statusLabels[invitation.status] || invitation.status;
      row.append(copy, state);
      list.appendChild(row);
    });
  }

  function loadReferral() {
    return fetch('/api/talent/referrals', {
      credentials: 'same-origin',
      headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
    }).then(function(response) { return response.json().then(function(body) {
      if (!response.ok || !body.ok) throw new Error(body.error || '<?= e(t('wallet.referral.unavailable', $lang)) ?>');
      return body;
    }); }).then(renderReferral).catch(function(error) {
      loading.textContent = error.message || '<?= e(t('wallet.referral.unavailable', $lang)) ?>';
      loading.classList.add('is-error');
    });
  }

  form.addEventListener('submit', function(event) {
    event.preventDefault();
    var button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    message.textContent = '<?= e(t('wallet.referral.sending', $lang)) ?>';
    fetch('/api/talent/referrals', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
      body: new URLSearchParams(new FormData(form)).toString()
    }).then(function(response) { return response.json().then(function(body) {
      if (!response.ok || !body.ok) throw new Error(body.error || '<?= e(t('wallet.referral.unavailable', $lang)) ?>');
      return body;
    }); }).then(function() {
      form.reset();
      message.textContent = '<?= e(t('wallet.referral.sent', $lang)) ?>';
      return loadReferral();
    }).catch(function(error) {
      message.textContent = error.message || '<?= e(t('wallet.referral.unavailable', $lang)) ?>';
    }).finally(function() { button.disabled = false; button.textContent = '<?= e(t('wallet.referral.send', $lang)) ?>'; });
  });

  loadReferral();
});
</script>
