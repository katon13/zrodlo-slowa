<?php 
use App\Services\CurrencyRateService;
$currencyService = new CurrencyRateService();
$lang = public_language();
$money = static fn($minor) => $currencyService->formatSimple(((int)$minor) / 100, 'PLN', $lang); 
?>
<section class="admin-page-head">
  <p class="kicker"><?= t('wallet.kicker') ?></p>
  <h1><?= t('wallet.topup_title') ?></h1>
  <p><?= t('wallet.topup_desc') ?></p>
</section>

<?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>

<section class="money-home-section">
  <div class="admin-section-head">
    <div><p class="kicker"><?= t('wallet.pln_wallet') ?></p><h2><?= t('wallet.topup_title') ?></h2></div>
    <a class="text-link" href="<?= e(public_language_url($lang, '/wallet')) ?>"><?= t('wallet.back_to_wallet') ?></a>
  </div>
  <div class="money-home-grid zs-topup-grid">
    <?php foreach ($packages as $package): ?>
      <article class="money-home-card zs-topup-package-card is-denomination">
        <div class="zs-package-tag"><?= t('wallet.safe_topup') ?></div>
        <div class="money-home-icon"><?= zs_icon('wallet') ?></div>
        <span class="zs-package-type"><?= t('wallet.external_payment') ?></span>
        <strong class="zs-package-amount"><?= $money($package['amount_minor'] ?? 0) ?></strong>
        <p class="zs-package-desc"><?= e($package['description'] ?? t('wallet.topup_desc')) ?></p>
        <form method="post" action="<?= e(public_language_url($lang, '/wallet/topup')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
          <button class="zs-btn-red is-large-cta" type="submit"><?= zs_icon('credit-card') ?> <?= t('wallet.go_to_payment') ?></button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div><p class="kicker"><?= t('wallet.orders_title') ?></p><h2><?= t('wallet.orders_title') ?></h2></div>
    <span class="zs-badge-count"><?= count($orders ?? []) ?> <?= t('wallet.orders_count_label') ?></span>
  </div>
  <?php if (empty($orders)): ?>
    <div class="zs-empty-state"><h3><?= t('wallet.no_orders') ?></h3><p><?= t('wallet.topup_desc') ?></p></div>
  <?php else: ?>
    <div class="zs-admin-table-wrapper">
      <table class="zs-admin-table">
        <thead>
          <tr>
            <th><?= t('wallet.history.table.id') ?></th>
            <th><?= t('wallet.orders.table.user') ?></th>
            <th><?= t('wallet.orders.table.package') ?></th>
            <th><?= t('wallet.history.table.status') ?></th>
            <th><?= t('wallet.orders.table.provider') ?></th>
            <th class="text-right"><?= t('wallet.history.table.amount') ?></th>
            <th><?= t('wallet.history.table.date') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td class="zs-id-cell">#<?= (int)$order['id'] ?></td>
              <td><strong><?= e($order['display_name'] ?: t('wallet.orders.default_user')) ?></strong><small style="display:block;font-size:0.8em;opacity:0.7;"><?= e($order['email'] ?? '') ?></small></td>
              <td><?= e($order['package_name'] ?: $order['public_id']) ?></td>
              <?php $statusClass = match((string)$order['status']) { 'credited', 'paid' => 'paid', 'failed' => 'failed', 'expired', 'cancelled' => 'cancelled', default => 'pending' }; ?>
              <td><span class="zs-status-badge is-<?= e($statusClass) ?> <?= e($statusClass) ?>"><?= e($order['status']) ?></span></td>
              <td><?= e($order['provider']) ?></td>
              <td class="text-right zs-amount-cell"><strong><?= $money($order['amount_minor']) ?></strong></td>
              <td><?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
