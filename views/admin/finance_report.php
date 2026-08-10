<?php 
$money = static fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . ' PLN'; 
$statusBadge = function($status, $map) {
    $m = $map[$status] ?? ['label' => $status, 'class' => 'neutral'];
    return sprintf('<span class="zs-status-badge is-%s">%s</span>', $m['class'], e($m['label']));
};
?>
<section class="admin-page-head zs-operator-page-head">
    <p class="kicker"><?= e(t('admin.finance_report.finanse_i_gospodarka')) ?></p>
    <h1><?= e(t('admin.finance_report.raport_finansowy')) ?></h1>
    <p><?= e(t('admin.finance_report.mapa_ekonomii_systemu_przepywy_portfele_sprzedaz_i_rozl_8c943c80')) ?></p>
</section>

<section class="settlement-grid">
    <div class="settlement-card">
        <span><?= e(t('admin.finance_report.portfele')) ?></span>
        <strong><?= (int)$wallets['cnt'] ?></strong>
        <small><?= e(str_replace('{amount}', $money($wallets['sum_available'] ?? 0), t('admin.finance_report.available_amount'))) ?></small>
    </div>
    <div class="settlement-card is-red">
        <span><?= e(t('admin.finance_report.rezerwacje')) ?></span>
        <strong><?= $money($wallets['sum_reserved'] ?? 0) ?></strong>
        <small><?= e(t('admin.finance_report.wypaty_w_toku')) ?></small>
    </div>
    <div class="settlement-card">
        <span><?= e(t('admin.finance_report.zdarzenia_ksiegowe')) ?></span>
        <strong><?= number_format((int)($economy_summary['transactions']['cnt'] ?? 0), 0, ',', ' ') ?></strong>
        <small><?= e(t('admin.finance_report.zdarzen_finansowych')) ?></small>
    </div>
    <div class="settlement-card">
        <span><?= e(t('admin.finance_report.punkty_talent')) ?></span>
        <strong><?= number_format((int)($wallets['sum_points'] ?? 0), 0, ',', ' ') ?></strong>
        <small><?= e(t('admin.finance_report.kapita_spoeczny')) ?></small>
    </div>
</section>

<section class="admin-panel-block zs-money-map-section">
    <div class="admin-section-head">
        <div>
            <p class="kicker"><?= e(t('admin.finance_report.ekonomia_zroda_sowa')) ?></p>
            <h2><?= e(t('admin.finance_report.mapa_przepywu_pieniedzy')) ?></h2>
        </div>
        <a class="text-link" href="/admin/ledger"><?= e(t('admin.finance_report.otworz_dziennik_finansowy')) ?></a>
    </div>

    <div class="zs-money-flow-grid">
        <?php foreach (($money_flows ?? []) as $flow): ?>
            <div class="zs-flow-item">
                <header class="zs-flow-header">
                    <div class="zs-flow-icon"><?= zs_icon($flow['icon'] ?? 'credit-card') ?></div>
                    <div class="zs-flow-title">
                        <h3><?= e($flow['label']) ?></h3>
                        <p><?= e($flow['note']) ?></p>
                    </div>
                </header>

                <div class="zs-flow-path">
                    <div class="zs-flow-step">
                        <span class="zs-step-label"><?= e(t('admin.finance_report.patnik')) ?></span>
                        <b class="zs-step-value"><?= e($flow['payer']) ?></b>
                    </div>
                    <div class="zs-flow-arrow"><?= zs_icon('arrow-right') ?></div>
                    <div class="zs-flow-step is-action">
                        <span class="zs-step-label"><?= e(t('admin.anti_fraud.akcja')) ?></span>
                        <b class="zs-step-value"><?= e($flow['action']) ?></b>
                    </div>
                    <div class="zs-flow-arrow"><?= zs_icon('arrow-right') ?></div>
                    <div class="zs-flow-step">
                        <span class="zs-step-label"><?= e(t('admin.finance_report.odbiorca')) ?></span>
                        <b class="zs-step-value"><?= e($flow['receiver']) ?></b>
                    </div>
                </div>

                <div class="zs-flow-ledger">
                    <span class="zs-ledger-label"><?= e(t('admin.finance_report.zapisy_w_systemie')) ?></span>
                    <div class="zs-ledger-tags">
                        <?php foreach ($flow['wallet'] as $tech => $human): ?>
                            <span class="zs-ledger-tag" title="<?= e($tech) ?>"><?= e($human) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="admin-form-grid">
    <div class="admin-panel-block">
        <div class="admin-section-head">
            <div>
                <p class="kicker"><?= e(t('admin.finance_report.sprzedaz_tresci')) ?></p>
                <h2><?= e(t('admin.finance_report.artykuy_premium_autor_serwis_safety_fund')) ?></h2>
            </div>
        </div>
        <ul class="zs-report-stats">
            <li>
                <span class="label"><?= e(t('admin.finance_report.suma_sprzedazy')) ?></span>
                <span class="value"><?= $money($premium['total_revenue'] ?? 0) ?></span>
                <small><?= e(str_replace('{count}', (string)(int)$premium['total_sales'], t('admin.finance_report.purchases_count'))) ?></small>
            </li>
            <li class="zs-stat-split">
                <div class="split-item">
                    <span class="label"><?= e(t('admin.finance_report.dla_autorow')) ?></span>
                    <span class="value is-positive"><?= $money($premium['total_author_income'] ?? 0) ?></span>
                </div>
                <div class="split-item">
                    <span class="label"><?= e(t('admin.finance_report.dla_serwisu')) ?></span>
                    <span class="value"><?= $money($premium['total_publisher_fee'] ?? 0) ?></span>
                </div>
                <div class="split-item">
                    <span class="label"><?= e(t('article.premium.safety_fund_share')) ?></span>
                    <span class="value is-positive"><?= $money($premium['total_safety_fund'] ?? 0) ?></span>
                </div>
            </li>
            <li>
                <span class="label"><?= e(t('admin.finance_report.aktywne_dostepy')) ?></span>
                <span class="value"><?= (int)$access['active_grants'] ?></span>
                <small><?= e(t('admin.finance_report.wazne_czasowo')) ?></small>
            </li>
        </ul>
    </div>

    <div class="admin-panel-block">
        <div class="admin-section-head">
            <div>
                <p class="kicker"><?= e(t('admin.finance_report.zaangazowanie')) ?></p>
                <h2><?= e(t('admin.finance_report.bonusy_i_nagrody')) ?></h2>
            </div>
        </div>
        <ul class="zs-report-stats">
            <li>
                <span class="label"><?= e(t('admin.finance_report.bonusy_aktywnosci')) ?></span>
                <span class="value"><?= $money($economy_summary['bonuses']['total'] ?? 0) ?></span>
                <small><?= number_format((int)($economy_summary['bonuses']['points'] ?? 0), 0, ',', ' ') ?> TT</small>
            </li>
            <li>
                <span class="label"><?= e(t('admin.finance_report.ankiety_i_badania')) ?></span>
                <span class="value"><?= $money($economy_summary['surveys']['rewards'] ?? 0) ?></span>
                <small><?= e(str_replace('{count}', (string)(int)($economy_summary['surveys']['cnt'] ?? 0), t('admin.finance_report.answers_count'))) ?></small>
            </li>
            <li>
                <span class="label"><?= e(t('admin.finance_report.kampanie_reklamowe')) ?></span>
                <span class="value"><?= $money($economy_summary['campaigns']['cost'] ?? 0) ?></span>
                <small><?= e(str_replace('{points}', number_format((int)($economy_summary['campaigns']['reward_points'] ?? 0), 0, ',', ' '), t('admin.finance_report.accrued_income_and_user_rewards'))) ?></small>
            </li>
        </ul>
    </div>
</section>

<section class="admin-form-grid">
    <div class="admin-panel-block">
        <div class="admin-section-head">
            <div><p class="kicker"><?= e(t('admin.finance_report.patnosci')) ?></p><h2><?= e(t('admin.finance_report.statusy_transakcji')) ?></h2></div>
            <a class="text-link" href="/admin/payments"><?= e(t('admin.finance_report.szczegoy')) ?></a>
        </div>
        <div class="zs-status-summary">
            <?php foreach ($payments as $p): ?>
                <div class="zs-status-row">
                    <?= $statusBadge($p['status'], $statusMap) ?>
                    <span class="zs-status-count"><?= (int)$p['cnt'] ?></span>
                    <strong class="zs-status-sum"><?= $money($p['sum_amount'] ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="admin-panel-block">
        <div class="admin-section-head">
            <div><p class="kicker"><?= e(t('wallet.payout_active')) ?></p><h2><?= e(t('admin.finance_report.realizacja_srodkow')) ?></h2></div>
            <a class="text-link" href="/admin/payouts"><?= e(t('admin.finance_report.zarzadzaj')) ?></a>
        </div>
        <div class="zs-status-summary">
            <?php foreach ($payouts as $p): ?>
                <div class="zs-status-row">
                    <?= $statusBadge($p['status'], $payoutStatusMap) ?>
                    <span class="zs-status-count"><?= (int)$p['cnt'] ?></span>
                    <strong class="zs-status-sum"><?= $money($p['sum_amount'] ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
