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
    <p class="kicker">Finanse i płatności</p>
    <h1>Płatności zewnętrzne</h1>
    <p>Centrum kontroli Stripe / Przelewy24, zamówień doładowania, webhooków i konwersji TT → PLN.</p>
</section>

<?php if ($m = ($_SESSION['_flash']['success'] ?? null)): unset($_SESSION['_flash']['success']); ?><div class="notice success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = ($_SESSION['_flash']['error'] ?? null)): unset($_SESSION['_flash']['error']); ?><div class="notice error"><?= e($m) ?></div><?php endif; ?>

<section class="zs-operator-overview" aria-label="Stan płatności zewnętrznych">
    <article class="<?= ($payment_settings['payments.enabled'] ?? '0') === '1' ? 'is-ready' : 'is-muted' ?>"><span>Płatności zewnętrzne</span><strong><?= ($payment_settings['payments.enabled'] ?? '0') === '1' ? 'AKTYWNE' : 'WYŁĄCZONE' ?></strong><small>Główny przełącznik modułu</small></article>
    <article class="<?= ($payment_settings['stripe.enabled'] ?? '0') === '1' ? 'is-ready' : 'is-warning' ?>"><span>Stripe</span><strong><?= ($payment_settings['stripe.enabled'] ?? '0') === '1' ? 'PODŁĄCZONY' : 'NIEAKTYWNY' ?></strong><small>Tryb: <?= e($payment_settings['stripe.mode'] ?? 'test') ?></small></article>
    <article><span>Kurs Słowa</span><strong><?= e($payment_settings['wallet.tt_per_pln'] ?? '10') ?> TT</strong><small>za 1 PLN</small></article>
    <article class="<?= (int)($payment_summary['failed_events_count'] ?? 0) > 0 ? 'is-warning' : 'is-ready' ?>"><span>Webhooki wymagające uwagi</span><strong><?= (int)($payment_summary['failed_events_count'] ?? 0) ?></strong><small><?= (int)($payment_summary['events_count'] ?? 0) ?> odebranych zdarzeń</small></article>
</section>


<section class="admin-panel-block zs-payment-admin-block zs-payment-settings-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker">Moduł Stripe</p>
            <h2>Konfiguracja Stripe</h2>
        </div>
        <span class="zs-badge-info">płatności zewnętrzne</span>
    </div>
    <form method="post" action="/admin/payments/settings" class="zs-payment-settings-form">
        <?= csrf_field() ?>
        <div class="zs-settings-grid">
            <label><span>Płatności zewnętrzne</span><select name="payments.enabled"><option value="0" <?= ($payment_settings['payments.enabled'] ?? '0') === '0' ? 'selected' : '' ?>>wyłączone</option><option value="1" <?= ($payment_settings['payments.enabled'] ?? '0') === '1' ? 'selected' : '' ?>>włączone</option></select></label>
            <label><span>Stripe</span><select name="stripe.enabled"><option value="0" <?= ($payment_settings['stripe.enabled'] ?? '0') === '0' ? 'selected' : '' ?>>wyłączony</option><option value="1" <?= ($payment_settings['stripe.enabled'] ?? '0') === '1' ? 'selected' : '' ?>>włączony</option></select></label>
            <label><span>Tryb Stripe</span><select name="stripe.mode"><option value="test" <?= ($payment_settings['stripe.mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>test</option><option value="live" <?= ($payment_settings['stripe.mode'] ?? 'test') === 'live' ? 'selected' : '' ?>>live</option></select></label>
            <label><span>Waluta Stripe</span><input name="stripe.currency" value="<?= e($payment_settings['stripe.currency'] ?? 'pln') ?>" placeholder="pln"></label>
            <label><span>Metody Stripe</span><input name="stripe.payment_methods" value="<?= e($payment_settings['stripe.payment_methods'] ?? 'card,p24') ?>" placeholder="card,p24"></label>
        </div>
        <p class="zs-settings-note">Klucze Stripe (Public, Secret, Webhook) oraz URL-e powrotne są konfigurowane w pliku .env.</p>
        <div class="zs-operator-savebar">
            <div><strong>Potwierdź zmianę konfiguracji</strong><span>Operacja zostanie zapisana w audycie administracyjnym.</span></div>
            <label><span>Hasło administratora</span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="Hasło chroniące zmianę"></label>
            <button class="zs-btn-red" type="submit">Zapisz ustawienia Stripe</button>
        </div>
    </form>
</section>

<section class="admin-panel-block zs-payment-admin-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker">Konwersja TT</p>
            <h2>Transfery i limity</h2>
        </div>
        <span class="zs-badge-info">operacyjne</span>
    </div>
    <form method="post" action="/admin/payments/settings" class="zs-payment-settings-form">
        <?= csrf_field() ?>
        <div class="zs-settings-grid">
            <label><span>Konwersja TT → PLN</span><select name="wallet.transfer.talent_to_pln.enabled"><option value="1" <?= ($payment_settings['wallet.transfer.talent_to_pln.enabled'] ?? '1') === '1' ? 'selected' : '' ?>>aktywna</option><option value="0" <?= ($payment_settings['wallet.transfer.talent_to_pln.enabled'] ?? '1') === '0' ? 'selected' : '' ?>>wyłączona</option></select></label>
            <label><span>Prowizja %</span><input type="number" min="0" max="30" name="wallet.transfer.talent_to_pln.fee_percent" value="<?= e($payment_settings['wallet.transfer.talent_to_pln.fee_percent'] ?? '5') ?>"></label>
            <label><span>Minimum TT</span><input type="number" min="1" name="wallet.transfer.talent_to_pln.min_talent" value="<?= e($payment_settings['wallet.transfer.talent_to_pln.min_talent'] ?? '100') ?>"></label>
            <label><span>Dzienny limit TT</span><input type="number" min="1" name="wallet.transfer.talent_to_pln.max_daily_talent" value="<?= e($payment_settings['wallet.transfer.talent_to_pln.max_daily_talent'] ?? '5000') ?>"></label>
            <label><span>Autoakceptacja do PLN</span><input name="wallet.transfer.talent_to_pln.auto_approve_below_pln_minor" value="<?= number_format(((int)($payment_settings['wallet.transfer.talent_to_pln.auto_approve_below_pln_minor'] ?? 5000)) / 100, 2, ',', '') ?>"></label>
            <label><span>PLN → TT</span><select name="wallet.transfer.pln_to_talent.enabled"><option value="1" <?= ($payment_settings['wallet.transfer.pln_to_talent.enabled'] ?? '1') === '1' ? 'selected' : '' ?>>aktywne</option><option value="0" <?= ($payment_settings['wallet.transfer.pln_to_talent.enabled'] ?? '1') === '0' ? 'selected' : '' ?>>wyłączone</option></select></label>
        </div>
        <div class="zs-operator-savebar">
            <div><strong>Potwierdź zmianę limitów</strong><span>Nowe limity obejmą kolejne zlecenia konwersji.</span></div>
            <label><span>Hasło administratora</span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="Hasło chroniące zmianę"></label>
            <button class="zs-btn-red" type="submit">Zapisz limity konwersji</button>
        </div>
    </form>
</section>

<section class="admin-panel-block zs-payment-admin-block zs-payment-rate-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker">Ekonomia serwisu</p>
            <h2>Kurs Słowa (TT / PLN)</h2>
        </div>
        <span class="zs-badge-info">ustawienie centralne</span>
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
            <label><span>Liczba TT za 1 PLN</span><input type="number" min="1" name="wallet.tt_per_pln" value="<?= e($payment_settings['wallet.tt_per_pln'] ?? '10') ?>"></label>
        </div>
        <p class="zs-settings-note">Kurs używany przez portfel, konwersję TT → PLN i wskaźnik KURS SŁOWA w menu głównym.</p>
        <div class="zs-operator-savebar">
            <div><strong>Potwierdź zmianę kursu</strong><span>Kurs wpływa na prezentację i rozliczanie kolejnych konwersji.</span></div>
            <label><span>Hasło administratora</span><input type="password" name="critical_password" required autocomplete="current-password" placeholder="Hasło chroniące zmianę"></label>
            <button class="zs-btn-red" type="submit">Zapisz centralny kurs</button>
        </div>
    </form>
</section>

<section class="admin-panel-block zs-payment-admin-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker">Konfiguracja</p>
            <h2>Klucze Stripe</h2>
        </div>
        <span class="zs-badge-info">Plik .env</span>
    </div>
    <details class="zs-operator-technical">
      <summary>Instrukcja wdrożeniowa dla administratora technicznego</summary>
      <div class="zs-admin-content-text">
        <p>Klucze Stripe wpisuje się w pliku <code>.env</code> w katalogu głównym projektu:</p>
        <pre class="zs-code-block">X:\zrodlo-slowa\.env</pre>
        
        <p>Przykład konfiguracji:</p>
        <pre class="zs-code-block">
STRIPE_ENABLED=true
STRIPE_MODE=test
STRIPE_PUBLIC_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_CURRENCY=pln
STRIPE_PAYMENT_METHODS=card,p24
STRIPE_CHECKOUT_SUCCESS_URL=http://localhost:8080/wallet/topup/success?session_id={CHECKOUT_SESSION_ID}
STRIPE_CHECKOUT_CANCEL_URL=http://localhost:8080/wallet/topup/cancel
STRIPE_WEBHOOK_URL=http://localhost:8080/stripe/webhook
        </pre>

        <ul class="zs-instruction-list">
            <li><code>pk_test</code> wpisujemy jako <strong>STRIPE_PUBLIC_KEY</strong></li>
            <li><code>sk_test</code> wpisujemy jako <strong>STRIPE_SECRET_KEY</strong></li>
            <li><code>whsec</code> wpisujemy jako <strong>STRIPE_WEBHOOK_SECRET</strong></li>
            <li>Sekret webhooka ze Stripe CLI jest inny niż sekret webhooka z Dashboardu.</li>
            <li>Po zmianie <code>.env</code> trzeba odświeżyć aplikację / zrestartować lokalny serwer PHP.</li>
        </ul>
      </div>
    </details>
</section>

<section class="zs-payment-summary-grid" aria-label="Podsumowanie płatności">
    <article class="zs-payment-summary-card">
        <span>Zamówienia</span>
        <strong><?= (int)($payment_summary['orders_count'] ?? 0) ?></strong>
        <small>lokalne payment_orders</small>
    </article>
    <article class="zs-payment-summary-card">
        <span>Zaksięgowano</span>
        <strong><?= $money($payment_summary['credited_sum'] ?? 0) ?></strong>
        <small>do portfela PLN</small>
    </article>
    <article class="zs-payment-summary-card">
        <span>Webhooki</span>
        <strong><?= (int)($payment_summary['events_count'] ?? 0) ?></strong>
        <small><?= (int)($payment_summary['failed_events_count'] ?? 0) ?> błędów</small>
    </article>
    <article class="zs-payment-summary-card is-red">
        <span>Prowizje konwersji</span>
        <strong><?= $money($payment_summary['transfer_fees_sum'] ?? 0) ?></strong>
        <small><?= (int)($payment_summary['transfers_count'] ?? 0) ?> transferów</small>
    </article>
</section>

<section class="admin-panel-block zs-payment-admin-block">
    <div class="admin-section-head">
        <div>
            <p class="kicker">Stripe / Przelewy24</p>
            <h2>Zamówienia doładowania portfela</h2>
        </div>
        <span class="zs-badge-info">webhook = źródło prawdy</span>
    </div>
    <?php if (empty($payment_orders)): ?>
        <div class="zs-empty-state"><h3>Brak zamówień.</h3><p>Po wejściu użytkownika w doładowanie i utworzeniu Stripe Checkout pojawią się tutaj lokalne zamówienia.</p></div>
    <?php else: ?>
        <div class="zs-admin-table-wrapper">
            <table class="zs-admin-table zs-payment-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Użytkownik</th>
                        <th>Pakiet</th>
                        <th>Status</th>
                        <th>Operator płatności</th>
                        <th>Session</th>
                        <th class="text-right">Kwota</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_orders as $order): ?>
                        <?php $st = $getStatus($order['status']); ?>
                        <tr>
                            <td class="zs-id-cell">#<?= (int)$order['id'] ?></td>
                            <td><strong><?= e($order['display_name'] ?: 'Użytkownik') ?></strong><small><?= e($order['email'] ?? '') ?></small></td>
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
            <p class="kicker">Potwierdzenia operatora płatności</p>
            <h2>Zdarzenia Stripe</h2>
        </div>
        <span class="zs-badge-info">każde zdarzenie rozliczane raz</span>
    </div>
    <?php if (empty($gateway_events)): ?>
        <div class="zs-empty-state"><h3>Nie odebrano jeszcze webhooków.</h3><p>Po uruchomieniu Stripe CLI albo endpointu produkcyjnego zdarzenia będą widoczne tutaj.</p></div>
    <?php else: ?>
        <div class="zs-admin-table-wrapper">
            <table class="zs-admin-table zs-payment-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Zamówienie</th>
                        <th>Session</th>
                        <th>Błąd / uwaga</th>
                        <th>Odebrano</th>
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
            <p class="kicker">Dwa portfele</p>
            <h2>Konwersje Talent → PLN</h2>
        </div>
        <span class="zs-badge-info">prowizja systemu</span>
    </div>
    <?php if (empty($wallet_transfers)): ?>
        <div class="zs-empty-state"><h3>Brak transferów między portfelami.</h3><p>Po wykonaniu konwersji Talent → PLN system pokaże kwotę źródłową, kwotę netto i prowizję.</p></div>
    <?php else: ?>
        <div class="zs-admin-table-wrapper">
            <table class="zs-admin-table zs-payment-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Użytkownik</th>
                        <th>Kierunek</th>
                        <th>Status</th>
                        <th>Źródło</th>
                        <th>Cel</th>
                        <th class="text-right">Prowizja</th>
                        <th>Data</th>
                        <th class="text-right">Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wallet_transfers as $transfer): ?>
                        <?php $st = $getStatus($transfer['status']); ?>
                        <tr>
                            <td class="zs-id-cell">#<?= (int)$transfer['id'] ?></td>
                            <td><strong><?= e($transfer['display_name'] ?: 'Użytkownik') ?></strong><small><?= e($transfer['email'] ?? '') ?></small></td>
                            <td><?= e($transfer['direction']) ?></td>
                            <td><span class="zs-status-badge is-<?= e($st['class']) ?> <?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
                            <td><?= number_format((int)$transfer['source_amount'], 0, ',', ' ') ?> Talentów</td>
                            <td><?= $money($transfer['target_amount']) ?></td>
                            <td class="text-right zs-amount-cell"><strong><?= $money($transfer['fee_amount']) ?></strong></td>
                            <td><?= date('d.m.Y H:i', strtotime($transfer['created_at'])) ?></td>
                            <td class="text-right">
                                <?php if (in_array((string)$transfer['status'], ['held','pending','approved'], true)): ?>
                                    <div class="zs-transfer-actions">
                                        <form method="post" action="/admin/payments/transfers/approve">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                                            <input class="zs-admin-input-tiny" type="password" name="critical_password" placeholder="Hasło administratora" required autocomplete="current-password">
                                            <button class="btn-red compact" type="submit">OK</button>
                                        </form>
                                        <form method="post" action="/admin/payments/transfers/reject">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                                            <input class="zs-admin-input-tiny" type="password" name="critical_password" placeholder="Hasło administratora" required autocomplete="current-password">
                                            <input class="zs-admin-input-tiny" name="reason" placeholder="powód">
                                            <button class="btn-outline compact" type="submit">STOP</button>
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
            <p class="kicker">Moduł płatności</p>
            <h2>Płatności bezpośrednie</h2>
        </div>
        <span class="zs-badge-info">płatności</span>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table zs-admin-table zs-payment-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Typ transakcji</th>
                    <th>Kwota</th>
                    <th>Status</th>
                    <th>Operator</th>
                    <th style="text-align: right;">Akcja / Status</th>
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
                            <input class="zs-admin-input-tiny" type="password" name="critical_password" placeholder="Hasło administratora" required autocomplete="current-password">
                            <div class="zs-inline-action">
                                <input name="external_id" placeholder="ID zewn." class="zs-admin-input-tiny">
                                <button class="btn-red compact" type="submit" title="Potwierdź płatność">OK</button>
                            </div>
                        </form>
                        <?php elseif (strtolower($p['status']) === 'paid'): ?>
                        <span class="zs-settled-text">Rozliczona</span>
                        <?php else: ?>
                        <span class="zs-settled-text">Potwierdza operator</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="6">Brak wpisów w module payments.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

</div>
