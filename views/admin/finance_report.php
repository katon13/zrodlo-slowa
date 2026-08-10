<?php 
$money = static fn($minor) => number_format(((int)$minor) / 100, 2, ',', ' ') . ' PLN'; 
$statusBadge = function($status, $map) {
    $m = $map[$status] ?? ['label' => $status, 'class' => 'neutral'];
    return sprintf('<span class="zs-status-badge is-%s">%s</span>', $m['class'], e($m['label']));
};
?>
<section class="admin-page-head zs-operator-page-head">
    <p class="kicker">Finanse i Gospodarka</p>
    <h1>Raport finansowy</h1>
    <p>Mapa ekonomii systemu: przepływy, portfele, sprzedaż i rozliczenia w ujęciu redakcyjnym.</p>
</section>

<section class="settlement-grid">
    <div class="settlement-card">
        <span>PORTFELE</span>
        <strong><?= (int)$wallets['cnt'] ?></strong>
        <small><?= $money($wallets['sum_available'] ?? 0) ?> dostępne</small>
    </div>
    <div class="settlement-card is-red">
        <span>REZERWACJE</span>
        <strong><?= $money($wallets['sum_reserved'] ?? 0) ?></strong>
        <small>wypłaty w toku</small>
    </div>
    <div class="settlement-card">
        <span>ZDARZENIA KSIĘGOWE</span>
        <strong><?= number_format((int)($economy_summary['transactions']['cnt'] ?? 0), 0, ',', ' ') ?></strong>
        <small>zdarzeń finansowych</small>
    </div>
    <div class="settlement-card">
        <span>PUNKTY TALENT</span>
        <strong><?= number_format((int)($wallets['sum_points'] ?? 0), 0, ',', ' ') ?></strong>
        <small>kapitał społeczny</small>
    </div>
</section>

<section class="admin-panel-block zs-money-map-section">
    <div class="admin-section-head">
        <div>
            <p class="kicker">Ekonomia ŹRÓDŁA SŁOWA</p>
            <h2>Mapa przepływu pieniędzy</h2>
        </div>
        <a class="text-link" href="/admin/ledger">Otwórz dziennik finansowy</a>
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
                        <span class="zs-step-label">Płatnik</span>
                        <b class="zs-step-value"><?= e($flow['payer']) ?></b>
                    </div>
                    <div class="zs-flow-arrow"><?= zs_icon('arrow-right') ?></div>
                    <div class="zs-flow-step is-action">
                        <span class="zs-step-label">Akcja</span>
                        <b class="zs-step-value"><?= e($flow['action']) ?></b>
                    </div>
                    <div class="zs-flow-arrow"><?= zs_icon('arrow-right') ?></div>
                    <div class="zs-flow-step">
                        <span class="zs-step-label">Odbiorca</span>
                        <b class="zs-step-value"><?= e($flow['receiver']) ?></b>
                    </div>
                </div>

                <div class="zs-flow-ledger">
                    <span class="zs-ledger-label">Zapisy w systemie:</span>
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
                <p class="kicker">Sprzedaż treści</p>
                <h2>Artykuły Premium — Autor / Serwis / Safety Fund</h2>
            </div>
        </div>
        <ul class="zs-report-stats">
            <li>
                <span class="label">Suma sprzedaży</span>
                <span class="value"><?= $money($premium['total_revenue'] ?? 0) ?></span>
                <small><?= (int)$premium['total_sales'] ?> zakupów</small>
            </li>
            <li class="zs-stat-split">
                <div class="split-item">
                    <span class="label">Dla Autorów</span>
                    <span class="value is-positive"><?= $money($premium['total_author_income'] ?? 0) ?></span>
                </div>
                <div class="split-item">
                    <span class="label">Dla Serwisu</span>
                    <span class="value"><?= $money($premium['total_publisher_fee'] ?? 0) ?></span>
                </div>
                <div class="split-item">
                    <span class="label">Safety Fund</span>
                    <span class="value is-positive"><?= $money($premium['total_safety_fund'] ?? 0) ?></span>
                </div>
            </li>
            <li>
                <span class="label">Aktywne dostępy</span>
                <span class="value"><?= (int)$access['active_grants'] ?></span>
                <small>ważne czasowo</small>
            </li>
        </ul>
    </div>

    <div class="admin-panel-block">
        <div class="admin-section-head">
            <div>
                <p class="kicker">Zaangażowanie</p>
                <h2>Bonusy i nagrody</h2>
            </div>
        </div>
        <ul class="zs-report-stats">
            <li>
                <span class="label">Bonusy aktywności</span>
                <span class="value"><?= $money($economy_summary['bonuses']['total'] ?? 0) ?></span>
                <small><?= number_format((int)($economy_summary['bonuses']['points'] ?? 0), 0, ',', ' ') ?> TT</small>
            </li>
            <li>
                <span class="label">Ankiety i badania</span>
                <span class="value"><?= $money($economy_summary['surveys']['rewards'] ?? 0) ?></span>
                <small><?= (int)($economy_summary['surveys']['cnt'] ?? 0) ?> odpowiedzi</small>
            </li>
            <li>
                <span class="label">Kampanie reklamowe</span>
                <span class="value"><?= $money($economy_summary['campaigns']['cost'] ?? 0) ?></span>
                <small>naliczony przychód · użytkownicy: <?= number_format((int)($economy_summary['campaigns']['reward_points'] ?? 0), 0, ',', ' ') ?> TT</small>
            </li>
        </ul>
    </div>
</section>

<section class="admin-form-grid">
    <div class="admin-panel-block">
        <div class="admin-section-head">
            <div><p class="kicker">Płatności</p><h2>Statusy transakcji</h2></div>
            <a class="text-link" href="/admin/payments">Szczegóły</a>
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
            <div><p class="kicker">Wypłaty</p><h2>Realizacja środków</h2></div>
            <a class="text-link" href="/admin/payouts">Zarządzaj</a>
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
