<?php
$money = static fn($minor, $currency='PLN') => number_format(((int)$minor) / 100, 2, ',', ' ') . ' ' . e($currency);
$getStatus = function($status) use ($statusMap) {
    return $statusMap[$status] ?? ['label' => $status, 'class' => 'default'];
};
$getType = function($type) use ($typeMap) {
    return $typeMap[$type] ?? $type;
};
$getProvider = function($provider) use ($providerMap) {
    return $providerMap[$provider] ?? $provider;
};
$short = static function($value, int $left = 14): string {
    $value = (string)$value;
    if ($value === '') return '—';
    return mb_strlen($value) > $left ? mb_substr($value, 0, $left) . '…' : $value;
};
?>
<div class="zs-operator-page zs-payments-operator-page">
<section class="admin-page-head">
    <p class="kicker"><?= e(t('admin.payments.finanse_i_patnosci')) ?></p>
    <h1><?= e(t('admin.payments.patnosci_zewnetrzne')) ?></h1>
    <p><?= e(t('admin.payments.centrum_kontroli_stripe_przelewy24_zamowien_doadowania_d4e8d352')) ?></p>
</section>

<?php if ($m = ($_SESSION['_flash']['success'] ?? null)): unset($_SESSION['_flash']['success']); ?><div class="notice success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = ($_SESSION['_flash']['error'] ?? null)): unset($_SESSION['_flash']['error']); ?><div class="notice error"><?= e($m) ?></div><?php endif; ?>

<section class="zs-operator-overview" aria-label="<?= e(t('admin.payments.stan_patnosci_zewnetrznych')) ?>">
    <article class="<?= ($payment_settings['payments.enabled'] ?? '0') === '1' ? 'is-ready' : 'is-muted' ?>"><span><?= e(t('admin.payments.patnosci_zewnetrzne')) ?></span><strong><?= e(($payment_settings['payments.enabled'] ?? '0') === '1' ? t('common.active') : t('common.inactive')) ?></strong><small><?= e(t('admin.payments.gowny_przeacznik_moduu')) ?></small></article>
    <article class="<?= ($payment_settings['stripe.enabled'] ?? '0') === '1' ? 'is-ready' : 'is-warning' ?>"><span><?= e(t('admin.payments.stripe')) ?></span><strong><?= e(($payment_settings['stripe.enabled'] ?? '0') === '1' ? t('admin.payments.podaczony') : t('common.inactive')) ?></strong><small><?= e(str_replace('{mode}', (string)($payment_settings['stripe.mode'] ?? 'test'), t('admin.payments.current_mode'))) ?></small></article>
    <article><span><?= e(t('admin.payments.kurs_sowa')) ?></span><strong><?= e($payment_settings['wallet.tt_per_pln'] ?? '10') ?> TT</strong><small><?= e(t('admin.payments.za_1_pln')) ?></small></article>
    <article class="<?= (int)($payment_summary['failed_events_count'] ?? 0) > 0 ? 'is-warning' : 'is-ready' ?>"><span><?= e(t('admin.payments.webhooki_wymagajace_uwagi')) ?></span><strong><?= (int)($payment_summary['failed_events_count'] ?? 0) ?></strong><small><?= e(str_replace('{count}', (string)(int)($payment_summary['events_count'] ?? 0), t('admin.payments.received_events_count'))) ?></small></article>
</section>


<section class="admin-panel-block zs-payment-admin-block zs-payment-settings-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker"><?= e(t('admin.payments.modu_stripe')) ?></p>
            <h2><?= e(t('admin.payments.konfiguracja_stripe')) ?></h2>
        </div>
        <span class="zs-badge-info"><?= e(t('admin.payments.patnosci_zewnetrzne_2')) ?></span>
    </div>
    <form method="post" action="/admin/payments/settings" class="zs-payment-settings-form">
        <?= csrf_field() ?>
        <div class="zs-settings-grid">
            <label><span><?= e(t('admin.payments.patnosci_zewnetrzne')) ?></span><select name="payments.enabled"><option value="0" <?= ($payment_settings['payments.enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= e(t('admin.ai.wyaczone')) ?></option><option value="1" <?= ($payment_settings['payments.enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= e(t('admin.ai.waczone')) ?></option></select></label>
            <label><span><?= e(t('admin.payments.stripe')) ?></span><select name="stripe.enabled"><option value="0" <?= ($payment_settings['stripe.enabled'] ?? '0') === '0' ? 'selected' : '' ?>><?= e(t('admin.payments.wyaczony')) ?></option><option value="1" <?= ($payment_settings['stripe.enabled'] ?? '0') === '1' ? 'selected' : '' ?>><?= e(t('admin.payments.waczony')) ?></option></select></label>
            <label><span><?= e(t('admin.payments.tryb_stripe')) ?></span><select name="stripe.mode"><option value="test" <?= ($payment_settings['stripe.mode'] ?? 'test') === 'test' ? 'selected' : '' ?>><?= e(t('admin.payments.test')) ?></option><option value="live" <?= ($payment_settings['stripe.mode'] ?? 'test') === 'live' ? 'selected' : '' ?>><?= e(t('admin.payments.live')) ?></option></select></label>
            <label><span><?= e(t('admin.payments.waluta_stripe')) ?></span><input name="stripe.currency" value="<?= e($payment_settings['stripe.currency'] ?? 'pln') ?>" placeholder="<?= e(t('admin.payments.pln')) ?>"></label>
            <label><span><?= e(t('admin.payments.metody_stripe')) ?></span><input name="stripe.payment_methods" value="<?= e($payment_settings['stripe.payment_methods'] ?? 'card,p24') ?>" placeholder="<?= e(t('admin.payments.card_p24')) ?>"></label>
        </div>
        <p class="zs-settings-note"><?= e(t('admin.payments.klucze_stripe_public_secret_webhook_oraz_url_e_powrotne_dc82edab')) ?></p>
        <div class="zs-operator-savebar">
            <div><strong><?= e(t('admin.payments.potwierdz_zmiane_konfiguracji')) ?></strong><span><?= e(t('admin.payments.operacja_zostanie_zapisana_w_audycie_administracyjnym')) ?></span></div>
            <label><span><?= e(t('admin.ai.haso_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
            <button class="zs-btn-red" type="submit"><?= e(t('admin.payments.zapisz_ustawienia_stripe')) ?></button>
        </div>
    </form>
</section>

<section class="admin-panel-block zs-payment-admin-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker"><?= e(t('admin.payments.konwersja_tt')) ?></p>
            <h2><?= e(t('admin.payments.transfery_i_limity')) ?></h2>
        </div>
        <span class="zs-badge-info"><?= e(t('admin.payments.operacyjne')) ?></span>
    </div>
    <form method="post" action="/admin/payments/settings" class="zs-payment-settings-form">
        <?= csrf_field() ?>
        <div class="zs-settings-grid">
            <label><span><?= e(t('admin.payments.konwersja_tt_pln')) ?></span><select name="wallet.transfer.talent_to_pln.enabled"><option value="1" <?= ($payment_settings['wallet.transfer.talent_to_pln.enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= e(t('admin.payments.aktywna')) ?></option><option value="0" <?= ($payment_settings['wallet.transfer.talent_to_pln.enabled'] ?? '1') === '0' ? 'selected' : '' ?>><?= e(t('admin.payments.wyaczona')) ?></option></select></label>
            <label><span><?= e(t('admin.payments.prowizja')) ?></span><input type="number" min="0" max="30" name="wallet.transfer.talent_to_pln.fee_percent" value="<?= e($payment_settings['wallet.transfer.talent_to_pln.fee_percent'] ?? '5') ?>"></label>
            <label><span><?= e(t('admin.payments.minimum_tt')) ?></span><input type="number" min="1" name="wallet.transfer.talent_to_pln.min_talent" value="<?= e($payment_settings['wallet.transfer.talent_to_pln.min_talent'] ?? '100') ?>"></label>
            <label><span><?= e(t('admin.payments.dzienny_limit_tt')) ?></span><input type="number" min="1" name="wallet.transfer.talent_to_pln.max_daily_talent" value="<?= e($payment_settings['wallet.transfer.talent_to_pln.max_daily_talent'] ?? '5000') ?>"></label>
            <label><span><?= e(t('admin.payments.autoakceptacja_do_pln')) ?></span><input name="wallet.transfer.talent_to_pln.auto_approve_below_pln_minor" value="<?= number_format(((int)($payment_settings['wallet.transfer.talent_to_pln.auto_approve_below_pln_minor'] ?? 5000)) / 100, 2, ',', '') ?>"></label>
            <label><span><?= e(t('admin.payments.pln_tt')) ?></span><select name="wallet.transfer.pln_to_talent.enabled"><option value="1" <?= ($payment_settings['wallet.transfer.pln_to_talent.enabled'] ?? '1') === '1' ? 'selected' : '' ?>><?= e(t('admin.payments.aktywne')) ?></option><option value="0" <?= ($payment_settings['wallet.transfer.pln_to_talent.enabled'] ?? '1') === '0' ? 'selected' : '' ?>><?= e(t('admin.ai.wyaczone')) ?></option></select></label>
        </div>
        <div class="zs-operator-savebar">
            <div><strong><?= e(t('admin.payments.potwierdz_zmiane_limitow')) ?></strong><span><?= e(t('admin.payments.nowe_limity_obejma_kolejne_zlecenia_konwersji')) ?></span></div>
            <label><span><?= e(t('admin.ai.haso_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
            <button class="zs-btn-red" type="submit"><?= e(t('admin.payments.zapisz_limity_konwersji')) ?></button>
        </div>
    </form>
</section>

<section class="admin-panel-block zs-payment-admin-block zs-payment-rate-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker"><?= e(t('admin.payments.ekonomia_serwisu')) ?></p>
            <h2><?= e(t('admin.payments.kurs_sowa_tt_pln')) ?></h2>
        </div>
        <span class="zs-badge-info"><?= e(t('admin.payments.ustawienie_centralne')) ?></span>
    </div>
    <form method="post" action="/admin/payments/settings" class="zs-payment-settings-form">
        <?= csrf_field() ?>
        <div class="zs-admin-content-text" style="margin-bottom: 20px;">
            <p style="font-size: 1.2rem; font-weight: bold; color: var(--zs-red, #c00); margin-bottom: 5px;">
                <?= e($payment_settings['wallet.tt_per_pln'] ?? '10') ?> TT = 1 PLN
            </p>
            <p><small style="color: #666;">1 TT = <?= number_format(1 / (max(1, (int)($payment_settings['wallet.tt_per_pln'] ?? 10))), 2, ',', ' ') ?> PLN</small></p>
        </div>
        <div class="zs-settings-grid">
            <label><span><?= e(t('admin.payments.liczba_tt_za_1_pln')) ?></span><input type="number" min="1" name="wallet.tt_per_pln" value="<?= e($payment_settings['wallet.tt_per_pln'] ?? '10') ?>"></label>
        </div>
        <p class="zs-settings-note"><?= e(t('admin.payments.kurs_uzywany_przez_portfel_konwersje_tt_pln_i_wskaznik_1d810d59')) ?></p>
        <div class="zs-operator-savebar">
            <div><strong><?= e(t('admin.payments.potwierdz_zmiane_kursu')) ?></strong><span><?= e(t('admin.payments.kurs_wpywa_na_prezentacje_i_rozliczanie_kolejnych_konwersji')) ?></span></div>
            <label><span><?= e(t('admin.ai.haso_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
            <button class="zs-btn-red" type="submit"><?= e(t('admin.payments.zapisz_centralny_kurs')) ?></button>
        </div>
    </form>
</section>

<section class="zs-payment-summary-grid" aria-label="<?= e(t('admin.payments.podsumowanie_patnosci')) ?>">
    <article class="zs-payment-summary-card">
        <span><?= e(t('admin.payments.zamowienia')) ?></span>
        <strong><?= (int)($payment_summary['orders_count'] ?? 0) ?></strong>
        <small><?= e(t('admin.payments.lokalne_payment_orders')) ?></small>
    </article>
    <article class="zs-payment-summary-card">
        <span><?= e(t('admin.payments.zaksiegowano')) ?></span>
        <strong><?= $money($payment_summary['credited_sum'] ?? 0) ?></strong>
        <small><?= e(t('admin.payments.do_portfela_pln')) ?></small>
    </article>
    <article class="zs-payment-summary-card">
        <span><?= e(t('admin.payments.webhooki')) ?></span>
        <strong><?= (int)($payment_summary['events_count'] ?? 0) ?></strong>
        <small><?= e(str_replace('{count}', (string)(int)($payment_summary['failed_events_count'] ?? 0), t('admin.payments.errors_count'))) ?></small>
    </article>
    <article class="zs-payment-summary-card is-red">
        <span><?= e(t('admin.payments.prowizje_konwersji')) ?></span>
        <strong><?= $money($payment_summary['transfer_fees_sum'] ?? 0) ?></strong>
        <small><?= e(str_replace('{count}', (string)(int)($payment_summary['transfers_count'] ?? 0), t('admin.payments.transfers_count'))) ?></small>
    </article>
</section>

<section class="admin-panel-block zs-payment-admin-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker"><?= e(t('admin.payments.stripe_przelewy24')) ?></p>
            <h2><?= e(t('admin.payments.zamowienia_doadowania_portfela')) ?></h2>
        </div>
        <span class="zs-badge-info"><?= e(t('admin.payments.webhook_zrodo_prawdy')) ?></span>
    </div>
    <?php if (empty($payment_orders)): ?>
        <div class="zs-empty-state"><h3><?= e(t('admin.payments.brak_zamowien')) ?></h3><p><?= e(t('admin.payments.po_wejsciu_uzytkownika_w_doadowanie_i_utworzeniu_stripe_0c496daa')) ?></p></div>
    <?php else: ?>
        <div class="zs-admin-table-wrapper">
            <table class="zs-admin-table zs-payment-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?= e(t('wallet.orders.table.user')) ?></th>
                        <th><?= e(t('wallet.orders.table.package')) ?></th>
                        <th><?= e(t('wallet.history.table.status')) ?></th>
                        <th><?= e(t('admin.payments.operator_patnosci')) ?></th>
                        <th><?= e(t('admin.payments.session')) ?></th>
                        <th class="text-right"><?= e(t('wallet.history.table.amount')) ?></th>
                        <th><?= e(t('wallet.history.table.date')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_orders as $order): ?>
                        <?php $st = $getStatus($order['status']); ?>
                        <tr>
                            <td class="zs-id-cell">#<?= (int)$order['id'] ?></td>
                            <td><strong><?= e($order['display_name'] ?: t('wallet.orders.table.user')) ?></strong><small><?= e($order['email'] ?? '') ?></small></td>
                            <td><?= e($order['package_name'] ?: $order['type']) ?><small><?= e($order['public_id']) ?></small></td>
                            <td><span class="zs-status-badge is-<?= e($st['class']) ?> <?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
                            <td><?= e($getProvider($order['provider'])) ?><small><?= e($order['method'] ?: 'checkout') ?></small></td>
                            <td><code><?= e($short($order['stripe_session_id'] ?? '')) ?></code></td>
                            <td class="text-right zs-amount-cell"><strong><?= $money($order['amount_minor'], $order['currency']) ?></strong></td>
                            <td><?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-panel-block zs-payment-admin-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker"><?= e(t('admin.payments.potwierdzenia_operatora_patnosci')) ?></p>
            <h2><?= e(t('admin.payments.zdarzenia_stripe')) ?></h2>
        </div>
        <span class="zs-badge-info"><?= e(t('admin.payments.kazde_zdarzenie_rozliczane_raz')) ?></span>
    </div>
    <?php if (empty($gateway_events)): ?>
        <div class="zs-empty-state"><h3><?= e(t('admin.payments.nie_odebrano_jeszcze_webhookow')) ?></h3><p><?= e(t('admin.payments.po_uruchomieniu_stripe_cli_albo_endpointu_produkcyjnego_82b3ab8a')) ?></p></div>
    <?php else: ?>
        <div class="zs-admin-table-wrapper">
            <table class="zs-admin-table zs-payment-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?= e(t('admin.payments.event')) ?></th>
                        <th><?= e(t('wallet.history.table.status')) ?></th>
                        <th><?= e(t('admin.payments.zamowienie')) ?></th>
                        <th><?= e(t('admin.payments.session')) ?></th>
                        <th><?= e(t('admin.payments.bad_uwaga')) ?></th>
                        <th><?= e(t('admin.payments.odebrano')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gateway_events as $event): ?>
                        <?php $st = $getStatus($event['processing_status']); ?>
                        <tr>
                            <td class="zs-id-cell">#<?= (int)$event['id'] ?></td>
                            <td><strong><?= e($event['event_type']) ?></strong><small><?= e($short($event['event_id'] ?? '', 22)) ?></small></td>
                            <td><span class="zs-status-badge is-<?= e($st['class']) ?> <?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
                            <td><?= e($event['order_public_id'] ?: '—') ?></td>
                            <td><code><?= e($short($event['stripe_session_id'] ?? '')) ?></code></td>
                            <td><?= e($event['error_message'] ?: '—') ?></td>
                            <td><?= date('d.m.Y H:i', strtotime($event['received_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-panel-block zs-payment-admin-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker"><?= e(t('admin.payments.dwa_portfele')) ?></p>
            <h2><?= e(t('admin.payments.konwersje_talent_pln')) ?></h2>
        </div>
        <span class="zs-badge-info"><?= e(t('admin.payments.prowizja_systemu')) ?></span>
    </div>
    <?php if (empty($wallet_transfers)): ?>
        <div class="zs-empty-state"><h3><?= e(t('admin.payments.brak_transferow_miedzy_portfelami')) ?></h3><p><?= e(t('admin.payments.po_wykonaniu_konwersji_talent_pln_system_pokaze_kwote_z_fb28c071')) ?></p></div>
    <?php else: ?>
        <div class="zs-admin-table-wrapper">
            <table class="zs-admin-table zs-payment-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?= e(t('wallet.orders.table.user')) ?></th>
                        <th><?= e(t('admin.payments.kierunek')) ?></th>
                        <th><?= e(t('wallet.history.table.status')) ?></th>
                        <th><?= e(t('admin.payments.zrodo')) ?></th>
                        <th><?= e(t('admin.payments.cel')) ?></th>
                        <th class="text-right"><?= e(t('wallet.conversion.fee')) ?></th>
                        <th><?= e(t('wallet.history.table.date')) ?></th>
                        <th class="text-right"><?= e(t('admin.anti_fraud.akcja')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wallet_transfers as $transfer): ?>
                        <?php $st = $getStatus($transfer['status']); ?>
                        <tr>
                            <td class="zs-id-cell">#<?= (int)$transfer['id'] ?></td>
                            <td><strong><?= e($transfer['display_name'] ?: t('wallet.orders.table.user')) ?></strong><small><?= e($transfer['email'] ?? '') ?></small></td>
                            <td><?= e($transfer['direction']) ?></td>
                            <td><span class="zs-status-badge is-<?= e($st['class']) ?> <?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
                            <td><?= e(str_replace('{points}', number_format((int)$transfer['source_amount'], 0, ',', ' '), t('admin.payments.talents_amount'))) ?></td>
                            <td><?= $money($transfer['target_amount']) ?></td>
                            <td class="text-right zs-amount-cell"><strong><?= $money($transfer['fee_amount']) ?></strong></td>
                            <td><?= date('d.m.Y H:i', strtotime($transfer['created_at'])) ?></td>
                            <td class="text-right">
                                <?php if (in_array((string)$transfer['status'], ['held','pending','approved'], true)): ?>
                                    <div class="zs-transfer-actions">
                                        <form method="post" action="/admin/payments/transfers/approve">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                                            <input class="zs-admin-input-tiny" type="password" name="critical_password" placeholder="<?= e(t('admin.ai.haso_administratora')) ?>" required autocomplete="current-password">
                                            <button class="btn-red compact" type="submit"><?= e(t('admin.payments.ok')) ?></button>
                                        </form>
                                        <form method="post" action="/admin/payments/transfers/reject">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                                            <input class="zs-admin-input-tiny" type="password" name="critical_password" placeholder="<?= e(t('admin.ai.haso_administratora')) ?>" required autocomplete="current-password">
                                            <input class="zs-admin-input-tiny" name="reason" placeholder="<?= e(t('admin.payments.powod')) ?>">
                                            <button class="btn-outline compact" type="submit"><?= e(t('admin.payments.stop')) ?></button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="zs-settled-text">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-panel-block zs-payment-admin-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker"><?= e(t('admin.payments.modu_patnosci')) ?></p>
            <h2><?= e(t('admin.payments.patnosci_bezposrednie')) ?></h2>
        </div>
        <span class="zs-badge-info"><?= e(t('admin.payments.patnosci')) ?></span>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table zs-admin-table zs-payment-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?= e(t('admin.payments.typ_transakcji')) ?></th>
                    <th><?= e(t('wallet.history.table.amount')) ?></th>
                    <th><?= e(t('wallet.history.table.status')) ?></th>
                    <th><?= e(t('wallet.orders.table.provider')) ?></th>
                    <th style="text-align: right;"><?= e(t('admin.payments.akcja_status')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="admin-id">#<?= (int)$p['id'] ?></td>
                    <td class="zs-type-cell"><?= e($getType($p['type'])) ?></td>
                    <td class="zs-amount-cell"><?= $money($p['amount_minor'], $p['currency']) ?></td>
                    <td>
                        <?php $st = $getStatus($p['status']); ?>
                        <span class="zs-status-badge <?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                    </td>
                    <td class="zs-provider-cell"><?= e($getProvider($p['provider'])) ?></td>
                    <td style="text-align: right;">
                        <?php if (strtolower($p['status']) !== 'paid' && (string)$p['provider'] === 'manual'): ?>
                        <form class="zs-compact-action-form" method="post" action="/admin/payments/manual-paid">
                            <?= csrf_field() ?>
                            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                            <input class="zs-admin-input-tiny" type="password" name="critical_password" placeholder="<?= e(t('admin.ai.haso_administratora')) ?>" required autocomplete="current-password">
                            <div class="zs-inline-action">
                                <input name="external_id" placeholder="<?= e(t('admin.payments.id_zewn')) ?>" class="zs-admin-input-tiny">
                                <button class="btn-red compact" type="submit" title="<?= e(t('admin.payments.potwierdz_patnosc')) ?>"><?= e(t('admin.payments.ok')) ?></button>
                            </div>
                        </form>
                        <?php elseif (strtolower($p['status']) === 'paid'): ?>
                        <span class="zs-settled-text"><?= e(t('admin.payments.rozliczona')) ?></span>
                        <?php else: ?>
                        <span class="zs-settled-text"><?= e(t('admin.payments.potwierdza_operator')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="6"><?= e(t('admin.payments.brak_wpisow_w_module_payments')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

</div>
