
<div class="admin-container zs-settings-page zs-operator-page">
    <div class="admin-page-head">
        <div class="kicker">KONFIGURACJA SYSTEMU</div>
        <h1>Ustawienia i Talent</h1>
        <p class="admin-intro">Centrum ustawień ekonomii, Talentu i Snajpera Słowa. Tutaj zarządzasz fundamentami serwisu <?= t('brand.name') ?>.</p>
    </div>

    <?php
    $keyLabels = [
        'site.name' => 'Nazwa serwisu',
        'site.tagline' => 'Hasło serwisu',
        'migration.status' => 'Stan przygotowania serwisu',
        'premium_access_hours' => 'Czas dostępu premium (godziny)',
        'slowo_snajper.enabled' => 'SNAJPER SŁOWA',
        'slowo_snajper.strict_mode' => 'Tryb ścisły',
        'slowo_snajper.audit_enabled' => 'Audyt administracyjny',
        'slowo_snajper.editorial_panels_enabled' => 'Panel ról / Kafelki redakcyjne',
        'slowo_snajper.anti_fraud_enabled' => 'Antyfraud / Strażnik Słowa',
        'slowo_snajper.block_suspicious_rewards' => 'Blokowanie podejrzanych bonusów',
        'slowo_snajper.hold_payouts_on_high_risk' => 'Wstrzymanie wypłat przy ryzyku',
        'slowo_snajper.log_login_events' => 'Logowanie zdarzeń logowania',
    ];

    $keyGroups = [
        'SERWIS' => ['site.name', 'site.tagline', 'migration.status'],
        'EKONOMIA' => ['premium_access_hours'],
    ];

    $settingsByGroup = [];
    foreach ($settings as $s) {
        $foundGroup = 'POZOSTAŁE';
        foreach ($keyGroups as $group => $keys) {
            if (in_array($s['name'], $keys)) {
                $foundGroup = $group;
                break;
            }
        }
        // Ustawienia zarządzane przez dedykowane, walidowane panele nie mogą
        // pojawiać się drugi raz w surowym formularzu "Pozostałe".
        if (
            $s['name'] === 'publisher_fee_percent'
            || str_starts_with($s['name'], 'slowo_snajper.')
            || str_starts_with($s['name'], 'ai.')
            || str_starts_with($s['name'], 'payments.')
            || str_starts_with($s['name'], 'stripe.')
            || str_starts_with($s['name'], 'wallet.')
        ) {
            continue;
        }
        $settingsByGroup[$foundGroup][] = $s;
    }

    $snajper = $slowo_snajper ?? [];
    $limits = $snajper['limits'] ?? [];
    $anti = $snajper['anti_heavy_actions'] ?? [];
    $sens = $snajper['sensitivity'] ?? [];
    $rolesCfg = $snajper['roles'] ?? [];
    $antiFraudCfg = $snajper['anti_fraud'] ?? [];
    $uiCfg = $snajper['ui'] ?? [];
    $talentRuleGroups = is_array($talent_rule_groups ?? null) ? $talent_rule_groups : [];
    $visibleTalentRules = array_merge(...array_map(static fn(array $group): array => $group['rules'] ?? [], $talentRuleGroups));
    $talentRuleCount = count($visibleTalentRules);
    $activeTalentRuleCount = count(array_filter(
        $visibleTalentRules,
        static fn(array $rule): bool => !empty($rule['is_active'])
    ));
    $referralOverview = is_array($referral_overview ?? null) ? $referral_overview : [];
    $referralPromotion = is_array($referralOverview['promotion'] ?? null) ? $referralOverview['promotion'] : [];
    $referralCounts = is_array($referralOverview['status_counts'] ?? null) ? $referralOverview['status_counts'] : [];
    $referralRecent = is_array($referralOverview['recent_invitations'] ?? null) ? $referralOverview['recent_invitations'] : [];
    $promotionDateInput = static function (?string $value): string {
        if ($value === null || trim($value) === '') return '';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d\TH:i');
        } catch (Throwable) {
            return '';
        }
    };
    $settingValues = array_column($settings, 'value', 'name');
    ?>

    <section class="zs-operator-overview" aria-label="Podsumowanie ustawień systemu">
        <article class="<?= in_array((string)($settingValues['migration.status'] ?? ''), ['complete', 'completed', 'ready'], true) ? 'is-ready' : 'is-muted' ?>">
            <span>Serwis</span><strong><?= in_array((string)($settingValues['migration.status'] ?? ''), ['complete', 'completed', 'ready'], true) ? 'GOTOWY' : 'W PRZYGOTOWANIU' ?></strong><small>stan konfiguracji głównej</small>
        </article>
        <article class="<?= !empty($snajper['enabled']) ? 'is-ready' : 'is-warning' ?>">
            <span>Snajper Słowa</span><strong><?= !empty($snajper['enabled']) ? 'AKTYWNY' : 'WYŁĄCZONY' ?></strong><small>ochrona ruchu i ról</small>
        </article>
        <article class="<?= !empty($antiFraudCfg['enabled']) ? 'is-ready' : 'is-warning' ?>">
            <span>Kontrola ryzyka</span><strong><?= !empty($antiFraudCfg['enabled']) ? 'AKTYWNA' : 'WYŁĄCZONA' ?></strong><small>nagrody i wypłaty</small>
        </article>
        <article class="<?= $activeTalentRuleCount > 0 ? 'is-ready' : 'is-muted' ?>">
            <span>Program Talent</span><strong><?= $activeTalentRuleCount ?> / <?= $talentRuleCount ?></strong><small>aktywnych zasad nagradzania</small>
        </article>
    </section>

    <!-- 1. SERWIS -->
    <section class="zs-settings-section zs-operator-settings-section">
        <div class="zs-operator-section-head">
            <div><p class="kicker">Tożsamość serwisu</p><h2>Serwis</h2><p>Nazwa, hasło i stan przygotowania aplikacji widoczne dla operatora.</p></div>
            <span>Ustawienia główne</span>
        </div>
        <form action="/admin/settings" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span>Potwierdź hasłem administratora</span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="Hasło chroniące zmianę"></label>
            <div class="zs-settings-grid">
                <?php foreach ($settingsByGroup['SERWIS'] ?? [] as $s): ?>
                    <div class="zs-setting-item">
                        <label class="zs-setting-label"><?php echo e($keyLabels[$s['name']] ?? $s['name']); ?></label>
                        <div class="zs-setting-control">
                            <input type="text" name="settings[<?php echo e($s['name']); ?>]" value="<?php echo e($s['value']); ?>" class="zs-setting-input">
                        </div>
                        <div class="zs-setting-meta">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="zs-settings-actions">
                <button type="submit" class="btn-red">Zapisz ustawienia serwisu</button>
            </div>
        </form>
    </section>

    <!-- 2. EKONOMIA -->
    <section class="zs-settings-section zs-operator-settings-section">
        <div class="zs-operator-section-head">
            <div><p class="kicker">Model rozliczeń</p><h2>Ekonomia</h2><p>Czas dostępu do treści premium. Globalny podział Autor / Serwis / Safety Fund jest wersjonowany i zarządzany w module Safety Fund.</p></div>
            <span>Wpływa na rozliczenia</span>
        </div>
        <form action="/admin/settings" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span>Potwierdź hasłem administratora</span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="Hasło chroniące zmianę"></label>
            <div class="zs-settings-grid">
                <?php foreach ($settingsByGroup['EKONOMIA'] ?? [] as $s): ?>
                    <div class="zs-setting-item">
                        <label class="zs-setting-label"><?php echo e($keyLabels[$s['name']] ?? $s['name']); ?></label>
                        <div class="zs-setting-control">
                            <input type="text" name="settings[<?php echo e($s['name']); ?>]" value="<?php echo e($s['value']); ?>" class="zs-setting-input">
                        </div>
                        <div class="zs-setting-meta">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="zs-settings-actions">
                <button type="submit" class="btn-red">Zapisz ekonomię</button>
                <a class="btn-line" href="/admin/safety-fund#policy">Otwórz politykę Safety Fund</a>
            </div>
        </form>
    </section>

    <!-- 3. SNAJPER SŁOWA -->
    <section class="zs-settings-section zs-operator-settings-section" id="slowo-snajper">
        <div class="zs-operator-section-head">
            <div><p class="kicker">Ochrona operacyjna</p><h2>Snajper Słowa</h2><p>Limity zapytań, ochrona ról wysokich i zachowanie ciężkich operacji.</p></div>
            <span>Bezpieczeństwo ruchu</span>
        </div>
        
        <form action="/admin/settings/slowo-snajper" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span>Potwierdź hasłem administratora</span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="Hasło chroniące zmianę"></label>
            
            <h3>Status i tryby pracy</h3>
            <div class="zs-settings-grid">
                <div class="zs-setting-item">
                    <label class="zs-setting-label">SNAJPER SŁOWA</label>
                    <div class="zs-setting-control">
                        <select name="snajper[enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($snajper['enabled']) ? 'selected' : ''; ?>>Włączony</option>
                            <option value="0" <?php echo empty($snajper['enabled']) ? 'selected' : ''; ?>>Wyłączony</option>
                        </select>
                    </div>
                    <p class="zs-setting-description">Włącza limity i precyzyjne ładowanie danych.</p>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Audyt administracyjny</label>
                    <div class="zs-setting-control">
                        <select name="snajper[audit_enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($snajper['audit_enabled']) ? 'selected' : ''; ?>>Włączony</option>
                            <option value="0" <?php echo empty($snajper['audit_enabled']) ? 'selected' : ''; ?>>Wyłączony</option>
                        </select>
                    </div>
                    <p class="zs-setting-description">Zapisuje ślad zmian ról, statusów i zgód.</p>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Tryb ścisły</label>
                    <div class="zs-setting-control">
                        <select name="snajper[strict_mode]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($snajper['strict_mode']) ? 'selected' : ''; ?>>Włączony</option>
                            <option value="0" <?php echo empty($snajper['strict_mode']) ? 'selected' : ''; ?>>Wyłączony</option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Kompaktowe bonusy</label>
                    <div class="zs-setting-control">
                        <select name="snajper[ui][compact_bonus_rows]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($uiCfg['compact_bonus_rows']) ? 'selected' : ''; ?>>TAK</option>
                            <option value="0" <?php echo empty($uiCfg['compact_bonus_rows']) ? 'selected' : ''; ?>>NIE</option>
                        </select>
                    </div>
                </div>
            </div>

            <h3>Bezpieczeństwo i Panele Ról</h3>
            <div class="zs-settings-grid">
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Kafelki redakcyjne / Panele ról</label>
                    <div class="zs-setting-control">
                        <select name="snajper[roles][editorial_panels_enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($rolesCfg['editorial_panels_enabled']) ? 'selected' : ''; ?>>Aktywne</option>
                            <option value="0" <?php echo empty($rolesCfg['editorial_panels_enabled']) ? 'selected' : ''; ?>>Ukryte</option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Przydział ról w administracji</label>
                    <div class="zs-setting-control">
                        <select name="snajper[roles][admin_role_assignment_enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($rolesCfg['admin_role_assignment_enabled']) ? 'selected' : ''; ?>>Włączony</option>
                            <option value="0" <?php echo empty($rolesCfg['admin_role_assignment_enabled']) ? 'selected' : ''; ?>>Wyłączony</option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Wymagaj 2FA dla ról wysokich</label>
                    <div class="zs-setting-control">
                        <select name="snajper[roles][higher_roles_require_2fa]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($rolesCfg['higher_roles_require_2fa']) ? 'selected' : ''; ?>>TAK / Wymagaj</option>
                            <option value="0" <?php echo empty($rolesCfg['higher_roles_require_2fa']) ? 'selected' : ''; ?>>NIE</option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Wymagaj e-mail dla ról wysokich</label>
                    <div class="zs-setting-control">
                        <select name="snajper[roles][higher_roles_require_verified_email]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($rolesCfg['higher_roles_require_verified_email']) ? 'selected' : ''; ?>>TAK / Wymagaj</option>
                            <option value="0" <?php echo empty($rolesCfg['higher_roles_require_verified_email']) ? 'selected' : ''; ?>>NIE</option>
                        </select>
                    </div>
                </div>
            </div>

            <h3>Liczba pozycji wyświetlanych w panelach</h3>
            <div class="zs-settings-grid">
                <?php
                $limitLabels = [
                    'public_articles' => 'Artykuły publiczne',
                    'author_articles' => 'Panel autora / teksty',
                    'wallet_transactions' => 'Transakcje portfela',
                    'admin_articles' => 'Admin / artykuły',
                    'admin_users' => 'Admin / użytkownicy',
                    'admin_surveys' => 'Admin / ankiety',
                    'admin_campaigns' => 'Admin / kampanie',
                ];
                ?>
                <?php foreach ($limitLabels as $key => $label): ?>
                    <div class="zs-setting-item">
                        <label class="zs-setting-label"><?php echo e($label); ?></label>
                        <div class="zs-setting-control">
                            <input type="number" min="1" max="500" name="snajper[limits][<?php echo e($key); ?>]" value="<?php echo e((string)($limits[$key] ?? 50)); ?>" class="zs-setting-input">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3>Działania o podwyższonym ryzyku</h3>
            <div class="zs-settings-grid">
                <?php
                $antiLabels = [
                    'allow_full_table_admin_lists' => 'Pełne listy bez limitu',
                    'allow_hard_user_clean' => 'Twarde czyszczenie użytkownika',
                    'allow_database_reset_from_admin' => 'Reset bazy z panelu admina',
                ];
                ?>
                <?php foreach ($antiLabels as $key => $label): ?>
                    <div class="zs-setting-item">
                        <label class="zs-setting-label"><?php echo e($label); ?></label>
                        <div class="zs-setting-control">
                            <select name="snajper[anti_heavy_actions][<?php echo e($key); ?>]" class="zs-setting-input">
                                <option value="1" <?php echo !empty($anti[$key]) ? 'selected' : ''; ?>>DOZWOLONE</option>
                                <option value="0" <?php echo empty($anti[$key]) ? 'selected' : ''; ?>>ZABLOKOWANE</option>
                            </select>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="zs-settings-actions">
                <button type="submit" class="btn-red">Zapisz ustawienia Snajpera</button>
            </div>
        </form>
    </section>

    <!-- 4. ANTYFRAUD / STRAŻNIK SŁOWA -->
    <section class="zs-settings-section zs-operator-settings-section">
        <div class="zs-operator-section-head">
            <div><p class="kicker">Ochrona finansowa</p><h2>Ochrona nagród i wypłat</h2><p>Ustal, kiedy system ma zatrzymać nietypową nagrodę lub wypłatę do sprawdzenia.</p></div>
            <span>Kontrola ryzyka</span>
        </div>
        <form action="/admin/settings/slowo-snajper" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span>Potwierdź hasłem administratora</span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="Hasło chroniące zmianę"></label>
            <div class="zs-settings-grid">
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Automatyczna kontrola ryzyka</label>
                    <div class="zs-setting-control">
                        <select name="snajper[anti_fraud][enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($antiFraudCfg['enabled']) ? 'selected' : ''; ?>>Włączony</option>
                            <option value="0" <?php echo empty($antiFraudCfg['enabled']) ? 'selected' : ''; ?>>Wyłączony</option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Blokowanie podejrzanych nagród</label>
                    <div class="zs-setting-control">
                        <select name="snajper[anti_fraud][block_suspicious_rewards]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($antiFraudCfg['block_suspicious_rewards']) ? 'selected' : ''; ?>>TAK / Blokuj</option>
                            <option value="0" <?php echo empty($antiFraudCfg['block_suspicious_rewards']) ? 'selected' : ''; ?>>NIE / Tylko loguj</option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Wstrzymanie wypłat przy ryzyku</label>
                    <div class="zs-setting-control">
                        <select name="snajper[anti_fraud][hold_payouts_on_high_risk]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($antiFraudCfg['hold_payouts_on_high_risk']) ? 'selected' : ''; ?>>TAK / Wstrzymuj</option>
                            <option value="0" <?php echo empty($antiFraudCfg['hold_payouts_on_high_risk']) ? 'selected' : ''; ?>>NIE</option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Poziom ryzyka wstrzymujący wypłatę (0–100)</label>
                    <div class="zs-setting-control">
                        <input type="number" name="snajper[sensitivity][risk_score_hold_payout]" value="<?php echo e((string)($sens['risk_score_hold_payout'] ?? 80)); ?>" class="zs-setting-input">
                    </div>
                </div>
            </div>

            <h3>Poziomy kontroli</h3>
            <div class="zs-settings-grid">
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Poziom ryzyka pokazujący ostrzeżenie (0–100)</label>
                    <div class="zs-setting-control">
                        <input type="number" name="snajper[sensitivity][risk_score_warn]" value="<?php echo e((string)($sens['risk_score_warn'] ?? 60)); ?>" class="zs-setting-input">
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label">Maksymalna liczba bonusów dziennie</label>
                    <div class="zs-setting-control">
                        <input type="number" name="snajper[sensitivity][max_user_daily_bonus_events]" value="<?php echo e((string)($sens['max_user_daily_bonus_events'] ?? 40)); ?>" class="zs-setting-input">
                    </div>
                </div>
            </div>

            <div class="zs-settings-actions">
                <button type="submit" class="btn-red">Zapisz ustawienia antyfraudu</button>
            </div>
        </form>
    </section>

    <!-- 5. PROGRAM TALENT -->
    <section class="zs-settings-section zs-talent-operator" id="program-talent">
        <div class="zs-talent-section-head">
            <div>
                <p class="kicker">PROGRAM AKTYWNOŚCI</p>
                <h2>Program Talent</h2>
                <p>Ustal nagrody TT dla ośmiu działań, które system potrafi rzeczywiście potwierdzić. Wyłączona zasada nie przyznaje nowych nagród.</p>
            </div>
            <div class="zs-talent-summary" aria-label="Podsumowanie reguł programu Talent">
                <div><strong><?php echo $activeTalentRuleCount; ?></strong><span>aktywnych</span></div>
                <div><strong><?php echo $talentRuleCount; ?></strong><span>wszystkich reguł</span></div>
            </div>
        </div>

        <div class="zs-talent-presence-note">
            <?php echo zs_icon('shield'); ?>
            <div>
                <strong>Nagrody trafiają tylko do rozpoznanego użytkownika.</strong>
                <p>Aktywna wizyta dzienna wymaga bieżącego sygnału obecności z widocznej karty. Czytanie artykułu ma dodatkową kontrolę czasu i postępu.</p>
            </div>
        </div>

        <section class="zs-talent-group zs-referral-admin" id="talent-promotion" aria-labelledby="talent-promotion-title">
            <div class="zs-talent-group-head">
                <?php echo zs_icon('share'); ?>
                <div>
                    <p class="kicker">PROMOCJA APLIKACJI</p>
                    <h3 id="talent-promotion-title">Bonus za instalację i polecenie</h3>
                    <p>Kontrolowana promocja nad istniejącym Talentem. Kwota jest kopiowana do zaproszenia podczas wysyłki, więc późniejsza zmiana nie modyfikuje starych zaproszeń.</p>
                </div>
                <span class="zs-talent-state<?= !empty($referralPromotion['is_promoted']) ? ' is-active' : '' ?>"><?= !empty($referralPromotion['is_promoted']) ? 'PROMOWANE' : 'WYŁĄCZONE' ?></span>
            </div>

            <div class="zs-referral-admin-stats">
                <article><strong><?= (int)($referralCounts['mail_queued'] ?? 0) + (int)($referralCounts['sent'] ?? 0) + (int)($referralCounts['link_opened'] ?? 0) + (int)($referralCounts['installed'] ?? 0) + (int)($referralCounts['registered'] ?? 0) ?></strong><span>aktywnych zaproszeń</span></article>
                <article><strong><?= (int)($referralCounts['reward_queued'] ?? 0) + (int)($referralCounts['rewarded'] ?? 0) ?></strong><span>skutecznych poleceń</span></article>
                <article><strong><?= (int)($referralCounts['mail_dead_letter'] ?? 0) ?></strong><span>wiadomości wymagających uwagi</span></article>
            </div>

            <form action="/admin/settings/talent-promotion" method="POST" class="zs-talent-form zs-referral-promotion-form">
                <?php echo csrf_field(); ?>
                <div class="zs-talent-rule-card is-active">
                    <div class="zs-talent-rule-controls">
                        <label>
                            <span>Nagroda dla każdej strony</span>
                            <div class="zs-input-with-unit"><input type="number" min="1" max="1000000" step="1" required name="promotion[reward_points]" value="<?= e((string)($referralPromotion['reward_points'] ?? 1000)) ?>"><b>TT</b></div>
                        </label>
                        <label>
                            <span>Aktywne zaproszenia / osoba</span>
                            <div class="zs-input-with-unit"><input type="number" min="1" max="100" step="1" required name="promotion[active_invitation_limit]" value="<?= e((string)($referralPromotion['active_invitation_limit'] ?? 3)) ?>"><b>szt.</b></div>
                        </label>
                        <label>
                            <span>Skuteczne polecenia / osoba</span>
                            <div class="zs-input-with-unit"><input type="number" min="1" max="100" step="1" required name="promotion[successful_referral_limit]" value="<?= e((string)($referralPromotion['successful_referral_limit'] ?? 3)) ?>"><b>szt.</b></div>
                        </label>
                        <label>
                            <span>Ważność zaproszenia</span>
                            <div class="zs-input-with-unit"><input type="number" min="1" max="365" step="1" required name="promotion[invitation_valid_days]" value="<?= e((string)($referralPromotion['invitation_valid_days'] ?? 30)) ?>"><b>dni</b></div>
                        </label>
                        <label><span>Promuj od</span><input type="datetime-local" required name="promotion[starts_at]" value="<?= e($promotionDateInput($referralPromotion['starts_at'] ?? null)) ?>"></label>
                        <label><span>Promuj do</span><input type="datetime-local" name="promotion[ends_at]" value="<?= e($promotionDateInput($referralPromotion['ends_at'] ?? null)) ?>"><small>Puste pole oznacza brak daty końcowej.</small></label>
                        <label class="zs-talent-switch">
                            <input type="checkbox" name="promotion[is_promoted]" value="1" <?= !empty($referralPromotion['is_promoted']) ? 'checked' : '' ?>>
                            <span class="zs-talent-switch-track" aria-hidden="true"><i></i></span>
                            <span>Promuj w portfelu i na stronie „Jak zarabiać”</span>
                        </label>
                    </div>
                </div>
                <div class="zs-critical-confirmation">
                    <?php echo zs_icon('shield'); ?>
                    <div class="zs-critical-confirmation-copy"><strong>Zmiana kontrolowana przez 3DORS</strong><p>Nowa wartość obejmie wyłącznie zaproszenia utworzone po zapisie.</p></div>
                    <label><span>Hasło administratora</span><input type="password" name="critical_password" required autocomplete="current-password"></label>
                    <button type="submit" class="btn-red">Zapisz promocję</button>
                </div>
            </form>

            <div class="zs-referral-admin-history-head">
                <h4>Ostatnie zaproszenia</h4>
                <p>Historia pokazuje kwotę zapisaną przy wysłaniu, stan realizacji oraz wynik wysyłki e-mail.</p>
            </div>
            <?php if ($referralRecent !== []): ?>
                <div class="table-wrap zs-referral-admin-table">
                    <table>
                        <thead><tr><th>Data</th><th>Polecający</th><th>Zaproszony e-mail</th><th>Zapisana kwota</th><th>Status</th><th>Poczta</th></tr></thead>
                        <tbody>
                        <?php foreach ($referralRecent as $invitation): ?>
                            <tr>
                                <td><?= e((string)$invitation['created_at']) ?></td>
                                <td><?= e((string)$invitation['inviter_email']) ?></td>
                                <td><?= e((string)$invitation['invited_email']) ?></td>
                                <td><strong><?= number_format((int)$invitation['reward_points'], 0, ',', ' ') ?> TT</strong></td>
                                <td><?= e(match((string)$invitation['status']) { 'mail_queued'=>'Wysyłanie', 'sent'=>'Wysłane', 'link_opened'=>'Link otwarty', 'installed'=>'Aplikacja zainstalowana', 'registered'=>'Konto założone', 'reward_queued'=>'Nagroda w realizacji', 'rewarded'=>'Nagroda przyznana', default=>'Zakończone' }) ?></td>
                                <td><?= e(match((string)($invitation['mail_status'] ?? '')) { 'queued'=>'Oczekuje na wysłanie', 'sent'=>'Dostarczone do wysyłki', 'dead_letter'=>'Wymaga ponowienia', default=>'—' }) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="zs-referral-admin-empty">
                    <strong>Brak zaproszeń do wyświetlenia.</strong>
                    <span>Pierwsze zaproszenie pojawi się tutaj po wysłaniu go przez użytkownika z portfela.</span>
                </div>
            <?php endif; ?>
        </section>

        <form action="/admin/settings/talent-rules" method="POST" class="zs-talent-form">
            <?php echo csrf_field(); ?>

            <?php foreach ($talentRuleGroups as $group): ?>
                <section class="zs-talent-group" aria-labelledby="talent-group-<?php echo e((string)$group['key']); ?>">
                    <div class="zs-talent-group-head">
                        <?php echo zs_icon((string)$group['icon']); ?>
                        <div>
                            <h3 id="talent-group-<?php echo e((string)$group['key']); ?>"><?php echo e((string)$group['title']); ?></h3>
                            <p><?php echo e((string)$group['description']); ?></p>
                        </div>
                    </div>

                    <div class="zs-talent-cards">
                        <?php foreach ($group['rules'] as $r): ?>
                            <?php
                            $ruleType = (string)$r['activity_type'];
                            $fieldId = 'talent-' . str_replace('_', '-', $ruleType);
                            $isActive = !empty($r['is_active']);
                            $isResponsePublicationRule = $ruleType === 'response_publication_bonus';
                            $hasDailyLimit = $ruleType === 'article_read_bonus';
                            ?>
                            <article class="zs-talent-rule-card<?php echo $isActive ? ' is-active' : ''; ?><?php echo ($r['operator_tone'] ?? '') === 'warning' ? ' is-warning' : ''; ?>" data-talent-rule>
                                <div class="zs-talent-rule-main">
                                    <div class="zs-talent-rule-icon"><?php echo zs_icon((string)$r['operator_icon']); ?></div>
                                    <div class="zs-talent-rule-copy">
                                        <div class="zs-talent-rule-title-row">
                                            <h4><?php echo e((string)$r['operator_title']); ?></h4>
                                            <?php if (!empty($r['operator_badge'])): ?>
                                                <span class="zs-talent-rule-badge"><?php echo e((string)$r['operator_badge']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <p><?php echo e((string)$r['operator_description']); ?></p>
                                        <small class="zs-talent-human-trigger"><?php echo e((string)($r['operator_trigger'] ?? '')); ?></small>
                                    </div>
                                    <span class="zs-talent-state<?php echo $isActive ? ' is-active' : ''; ?>" data-talent-state><?php echo $isActive ? 'Aktywna' : 'Wyłączona'; ?></span>
                                </div>

                                <div class="zs-talent-rule-controls">
                                    <label for="<?php echo e($fieldId); ?>-points">
                                        <span>Punkty Talent</span>
                                        <div class="zs-input-with-unit">
                                            <input id="<?php echo e($fieldId); ?>-points" type="number" min="0" max="1000000" step="1" required name="rules[<?php echo e($ruleType); ?>][points]" value="<?php echo e((string)$r['points_amount']); ?>">
                                            <b>TT</b>
                                        </div>
                                    </label>
                                    <?php if ($isResponsePublicationRule): ?>
                                    <label for="<?php echo e($fieldId); ?>-deposit">
                                        <span>Kaucja przy wysłaniu</span>
                                        <div class="zs-input-with-unit">
                                            <input id="<?php echo e($fieldId); ?>-deposit" type="number" min="0" max="1000000" step="1" required name="rules[<?php echo e($ruleType); ?>][submission_deposit_points]" value="<?php echo e((string)($r['submission_deposit_points'] ?? 0)); ?>">
                                            <b>TT</b>
                                        </div>
                                        <small>0 wyłącza kaucję. Pobieramy ją tylko raz przy wysłaniu; po publikacji wraca do użytkownika.</small>
                                    </label>
                                    <?php endif; ?>
                                    <input type="hidden" name="rules[<?php echo e($ruleType); ?>][money]" value="0">
                                    <?php if ($hasDailyLimit): ?>
                                      <label for="<?php echo e($fieldId); ?>-limit"><span>Maksymalnie dziennie</span><div class="zs-input-with-unit"><input id="<?php echo e($fieldId); ?>-limit" type="number" min="0" max="1000" step="1" required name="rules[<?php echo e($ruleType); ?>][limit]" value="<?php echo e((string)$r['daily_limit']); ?>"><b>tekstów</b></div><small>0 oznacza brak dodatkowego limitu.</small></label>
                                    <?php else: ?>
                                      <input type="hidden" name="rules[<?php echo e($ruleType); ?>][limit]" value="<?= $ruleType === 'day_visit_bonus' ? '1' : '0' ?>">
                                    <?php endif; ?>
                                    <?php if ($isResponsePublicationRule): ?><div class="zs-talent-tt-only"><strong>Nagroda i kaucja są wyłącznie w TT</strong><span>Odrzucenie oznacza przepadek kaucji, a poprawka tej samej polemiki nie pobiera jej drugi raz.</span></div><?php endif; ?>
                                    <label class="zs-talent-switch" for="<?php echo e($fieldId); ?>-active">
                                        <input type="hidden" name="rules[<?php echo e($ruleType); ?>][exists]" value="1">
                                        <input id="<?php echo e($fieldId); ?>-active" type="checkbox" name="rules[<?php echo e($ruleType); ?>][active]" <?php echo $isActive ? 'checked' : ''; ?> data-talent-toggle>
                                        <span class="zs-talent-switch-track" aria-hidden="true"><i></i></span>
                                        <span>Przyznawaj tę nagrodę</span>
                                    </label>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="zs-critical-confirmation">
                <?php echo zs_icon('shield'); ?>
                <div class="zs-critical-confirmation-copy">
                    <strong>Potwierdź zmianę zasad nagradzania</strong>
                    <p>To ustawienie wpływa na salda użytkowników. 3DORS zapisze operację w historii bezpieczeństwa.</p>
                </div>
                <label for="talent-critical-password">
                    <span>Hasło administratora</span>
                    <input id="talent-critical-password" type="password" name="critical_password" required autocomplete="current-password">
                </label>
                <button type="submit" class="btn-red">Zapisz program Talent</button>
            </div>
        </form>
    </section>

    <!-- POZOSTAŁE -->
    <?php if (!empty($settingsByGroup['POZOSTAŁE'])): ?>
    <section class="zs-settings-section zs-operator-settings-section">
        <div class="zs-operator-section-head">
            <div><p class="kicker">Ustawienia dodatkowe</p><h2>Pozostałe</h2><p>Parametry pomocnicze, które nie należą do wyspecjalizowanych modułów.</p></div>
            <span>Zaawansowane</span>
        </div>
        <form action="/admin/settings" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span>Potwierdź hasłem administratora</span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="Hasło chroniące zmianę"></label>
            <div class="zs-settings-grid">
                <?php foreach ($settingsByGroup['POZOSTAŁE'] as $s): ?>
                    <div class="zs-setting-item">
                        <label class="zs-setting-label"><?php echo e($s['name']); ?></label>
                        <div class="zs-setting-control">
                            <input type="text" name="settings[<?php echo e($s['name']); ?>]" value="<?php echo e($s['value']); ?>" class="zs-setting-input">
                        </div>
                        <div class="zs-setting-meta">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="zs-settings-actions">
                <button type="submit" class="btn-red">Zapisz pozostałe</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <div class="admin-actions editorial-note">
        <a href="/admin" class="btn btn-secondary">Powrót do Dashboardu</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-talent-rule]').forEach(function (card) {
        var toggle = card.querySelector('[data-talent-toggle]');
        var state = card.querySelector('[data-talent-state]');
        if (!toggle || !state) return;

        var refreshState = function () {
            card.classList.toggle('is-active', toggle.checked);
            state.classList.toggle('is-active', toggle.checked);
            state.textContent = toggle.checked ? 'Aktywna' : 'Wyłączona';
        };

        toggle.addEventListener('change', refreshState);
        refreshState();
    });
});
</script>
