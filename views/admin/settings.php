
<div class="admin-container zs-settings-page zs-operator-page">
    <div class="admin-page-head">
        <div class="kicker"><?= e(t('admin.settings.konfiguracja_systemu')) ?></div>
        <h1><?= e(t('admin.settings.ustawienia_i_talent')) ?></h1>
        <p class="admin-intro"><?= e(str_replace('{brand}', t('brand.name'), t('admin.settings.intro'))) ?></p>
    </div>

    <?php
    $keyLabels = [
        'site.name' => t('admin.settings.site_name'),
        'site.tagline' => t('admin.settings.haso_serwisu'),
        'migration.status' => t('admin.settings.migration_status'),
        'premium_access_hours' => t('admin.settings.czas_dostepu_premium_godziny'),
        'slowo_snajper.enabled' => t('admin.dashboard.snajper_sowa'),
        'slowo_snajper.strict_mode' => t('admin.settings.tryb_scisy'),
        'slowo_snajper.audit_enabled' => t('admin.settings.administrative_audit'),
        'slowo_snajper.editorial_panels_enabled' => t('admin.settings.panel_rol_kafelki_redakcyjne'),
        'slowo_snajper.anti_fraud_enabled' => t('admin.settings.antyfraud_straznik_sowa'),
        'slowo_snajper.block_suspicious_rewards' => t('admin.settings.blokowanie_podejrzanych_bonusow'),
        'slowo_snajper.hold_payouts_on_high_risk' => t('admin.settings.wstrzymanie_wypat_przy_ryzyku'),
        'slowo_snajper.log_login_events' => t('admin.settings.logowanie_zdarzen_logowania'),
    ];

    $keyGroups = [
        'service' => ['site.name', 'site.tagline', 'migration.status'],
        'economy' => ['premium_access_hours'],
    ];

    $settingsByGroup = [];
    foreach ($settings as $s) {
        $foundGroup = 'other';
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

    <section class="zs-operator-overview" aria-label="<?= e(t('admin.settings.podsumowanie_ustawien_systemu')) ?>">
        <article class="<?= in_array((string)($settingValues['migration.status'] ?? ''), ['complete', 'completed', 'ready'], true) ? 'is-ready' : 'is-muted' ?>">
            <span><?= e(t('article.premium.platform_share')) ?></span><strong><?= e(t(in_array((string)($settingValues['migration.status'] ?? ''), ['complete', 'completed', 'ready'], true) ? 'admin.settings.status_ready' : 'admin.settings.status_preparing')) ?></strong><small><?= e(t('admin.settings.stan_konfiguracji_gownej')) ?></small>
        </article>
        <article class="<?= !empty($snajper['enabled']) ? 'is-ready' : 'is-warning' ?>">
            <span><?= e(t('admin.settings.snajper_sowa')) ?></span><strong><?= e(t(!empty($snajper['enabled']) ? 'admin.settings.status_active_masculine' : 'admin.settings.wyaczony_2')) ?></strong><small><?= e(t('admin.settings.ochrona_ruchu_i_rol')) ?></small>
        </article>
        <article class="<?= !empty($antiFraudCfg['enabled']) ? 'is-ready' : 'is-warning' ?>">
            <span><?= e(t('admin.settings.kontrola_ryzyka')) ?></span><strong><?= e(t(!empty($antiFraudCfg['enabled']) ? 'admin.settings.status_active_feminine' : 'admin.settings.wyaczona')) ?></strong><small><?= e(t('admin.settings.nagrody_i_wypaty')) ?></small>
        </article>
        <article class="<?= $activeTalentRuleCount > 0 ? 'is-ready' : 'is-muted' ?>">
            <span><?= e(t('referral.landing.kicker')) ?></span><strong><?= $activeTalentRuleCount ?> / <?= $talentRuleCount ?></strong><small><?= e(t('admin.settings.aktywnych_zasad_nagradzania')) ?></small>
        </article>
    </section>

    <!-- 1. SERWIS -->
    <section class="zs-settings-section zs-operator-settings-section">
        <div class="zs-operator-section-head">
            <div><p class="kicker"><?= e(t('admin.settings.tozsamosc_serwisu')) ?></p><h2><?= e(t('article.premium.platform_share')) ?></h2><p><?= e(t('admin.settings.nazwa_haso_i_stan_przygotowania_aplikacji_widoczne_dla_operatora')) ?></p></div>
            <span><?= e(t('admin.settings.ustawienia_gowne')) ?></span>
        </div>
        <form action="/admin/settings" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span><?= e(t('admin.settings.potwierdz_hasem_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
            <div class="zs-settings-grid">
                <?php foreach ($settingsByGroup['service'] ?? [] as $s): ?>
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
                <button type="submit" class="btn-red"><?= e(t('admin.settings.zapisz_ustawienia_serwisu')) ?></button>
            </div>
        </form>
    </section>

    <!-- 2. EKONOMIA -->
    <section class="zs-settings-section zs-operator-settings-section">
        <div class="zs-operator-section-head">
            <div><p class="kicker"><?= e(t('admin.settings.model_rozliczen')) ?></p><h2><?= e(t('admin.settings.ekonomia')) ?></h2><p><?= e(t('admin.settings.czas_dostepu_do_tresci_premium_globalny_podzia_autor_se_76c838e2')) ?></p></div>
            <span><?= e(t('admin.settings.wpywa_na_rozliczenia')) ?></span>
        </div>
        <form action="/admin/settings" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span><?= e(t('admin.settings.potwierdz_hasem_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
            <div class="zs-settings-grid">
                <?php foreach ($settingsByGroup['economy'] ?? [] as $s): ?>
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
                <button type="submit" class="btn-red"><?= e(t('admin.settings.zapisz_ekonomie')) ?></button>
                <a class="btn-line" href="/admin/safety-fund#policy"><?= e(t('admin.settings.otworz_polityke_safety_fund')) ?></a>
            </div>
        </form>
    </section>

    <!-- 3. SNAJPER SŁOWA -->
    <section class="zs-settings-section zs-operator-settings-section" id="slowo-snajper">
        <div class="zs-operator-section-head">
            <div><p class="kicker"><?= e(t('admin.settings.ochrona_operacyjna')) ?></p><h2><?= e(t('admin.settings.snajper_sowa')) ?></h2><p><?= e(t('admin.settings.limity_zapytan_ochrona_rol_wysokich_i_zachowanie_ciezki_067bfdaf')) ?></p></div>
            <span><?= e(t('admin.settings.bezpieczenstwo_ruchu')) ?></span>
        </div>
        
        <form action="/admin/settings/slowo-snajper" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span><?= e(t('admin.settings.potwierdz_hasem_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
            
            <h3><?= e(t('admin.settings.status_i_tryby_pracy')) ?></h3>
            <div class="zs-settings-grid">
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.dashboard.snajper_sowa')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($snajper['enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.waczony')) ?></option>
                            <option value="0" <?php echo empty($snajper['enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.wyaczony')) ?></option>
                        </select>
                    </div>
                    <p class="zs-setting-description"><?= e(t('admin.settings.wacza_limity_i_precyzyjne_adowanie_danych')) ?></p>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.audyt_administracyjny')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[audit_enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($snajper['audit_enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.waczony')) ?></option>
                            <option value="0" <?php echo empty($snajper['audit_enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.wyaczony')) ?></option>
                        </select>
                    </div>
                    <p class="zs-setting-description"><?= e(t('admin.settings.zapisuje_slad_zmian_rol_statusow_i_zgod')) ?></p>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.tryb_scisy')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[strict_mode]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($snajper['strict_mode']) ? 'selected' : ''; ?>><?= e(t('admin.settings.waczony')) ?></option>
                            <option value="0" <?php echo empty($snajper['strict_mode']) ? 'selected' : ''; ?>><?= e(t('admin.settings.wyaczony')) ?></option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.kompaktowe_bonusy')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[ui][compact_bonus_rows]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($uiCfg['compact_bonus_rows']) ? 'selected' : ''; ?>><?= e(t('admin.settings.tak')) ?></option>
                            <option value="0" <?php echo empty($uiCfg['compact_bonus_rows']) ? 'selected' : ''; ?>><?= e(t('admin.settings.nie')) ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <h3><?= e(t('admin.settings.bezpieczenstwo_i_panele_rol')) ?></h3>
            <div class="zs-settings-grid">
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.kafelki_redakcyjne_panele_rol')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[roles][editorial_panels_enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($rolesCfg['editorial_panels_enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.aktywne')) ?></option>
                            <option value="0" <?php echo empty($rolesCfg['editorial_panels_enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.ukryte')) ?></option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.przydzia_rol_w_administracji')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[roles][admin_role_assignment_enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($rolesCfg['admin_role_assignment_enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.waczony')) ?></option>
                            <option value="0" <?php echo empty($rolesCfg['admin_role_assignment_enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.wyaczony')) ?></option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.wymagaj_2fa_dla_rol_wysokich')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[roles][higher_roles_require_2fa]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($rolesCfg['higher_roles_require_2fa']) ? 'selected' : ''; ?>><?= e(t('admin.settings.tak_wymagaj')) ?></option>
                            <option value="0" <?php echo empty($rolesCfg['higher_roles_require_2fa']) ? 'selected' : ''; ?>><?= e(t('admin.settings.nie')) ?></option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.wymagaj_e_mail_dla_rol_wysokich')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[roles][higher_roles_require_verified_email]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($rolesCfg['higher_roles_require_verified_email']) ? 'selected' : ''; ?>><?= e(t('admin.settings.tak_wymagaj')) ?></option>
                            <option value="0" <?php echo empty($rolesCfg['higher_roles_require_verified_email']) ? 'selected' : ''; ?>><?= e(t('admin.settings.nie')) ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <h3><?= e(t('admin.settings.liczba_pozycji_wyswietlanych_w_panelach')) ?></h3>
            <div class="zs-settings-grid">
                <?php
                $limitLabels = [
                    'public_articles' => t('admin.settings.artykuy_publiczne'),
                    'author_articles' => t('admin.settings.limit_author_articles'),
                    'wallet_transactions' => t('admin.settings.limit_wallet_transactions'),
                    'admin_articles' => t('admin.settings.admin_artykuy'),
                    'admin_users' => t('admin.settings.admin_uzytkownicy'),
                    'admin_surveys' => t('admin.settings.limit_admin_surveys'),
                    'admin_campaigns' => t('admin.settings.limit_admin_campaigns'),
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

            <h3><?= e(t('admin.settings.dziaania_o_podwyzszonym_ryzyku')) ?></h3>
            <div class="zs-settings-grid">
                <?php
                $antiLabels = [
                    'allow_full_table_admin_lists' => t('admin.settings.pene_listy_bez_limitu'),
                    'allow_hard_user_clean' => t('admin.settings.twarde_czyszczenie_uzytkownika'),
                    'allow_database_reset_from_admin' => t('admin.settings.database_reset'),
                ];
                ?>
                <?php foreach ($antiLabels as $key => $label): ?>
                    <div class="zs-setting-item">
                        <label class="zs-setting-label"><?php echo e($label); ?></label>
                        <div class="zs-setting-control">
                            <select name="snajper[anti_heavy_actions][<?php echo e($key); ?>]" class="zs-setting-input">
                                <option value="1" <?php echo !empty($anti[$key]) ? 'selected' : ''; ?>><?= e(t('admin.settings.dozwolone')) ?></option>
                                <option value="0" <?php echo empty($anti[$key]) ? 'selected' : ''; ?>><?= e(t('admin.settings.zablokowane')) ?></option>
                            </select>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="zs-settings-actions">
                <button type="submit" class="btn-red"><?= e(t('admin.settings.zapisz_ustawienia_snajpera')) ?></button>
            </div>
        </form>
    </section>

    <!-- 4. ANTYFRAUD / STRAŻNIK SŁOWA -->
    <section class="zs-settings-section zs-operator-settings-section">
        <div class="zs-operator-section-head">
            <div><p class="kicker"><?= e(t('admin.settings.ochrona_finansowa')) ?></p><h2><?= e(t('admin.settings.ochrona_nagrod_i_wypat')) ?></h2><p><?= e(t('admin.settings.ustal_kiedy_system_ma_zatrzymac_nietypowa_nagrode_lub_w_1d1ac165')) ?></p></div>
            <span><?= e(t('admin.settings.kontrola_ryzyka')) ?></span>
        </div>
        <form action="/admin/settings/slowo-snajper" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span><?= e(t('admin.settings.potwierdz_hasem_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
            <div class="zs-settings-grid">
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.automatyczna_kontrola_ryzyka')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[anti_fraud][enabled]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($antiFraudCfg['enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.waczony')) ?></option>
                            <option value="0" <?php echo empty($antiFraudCfg['enabled']) ? 'selected' : ''; ?>><?= e(t('admin.settings.wyaczony')) ?></option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.blokowanie_podejrzanych_nagrod')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[anti_fraud][block_suspicious_rewards]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($antiFraudCfg['block_suspicious_rewards']) ? 'selected' : ''; ?>><?= e(t('admin.settings.tak_blokuj')) ?></option>
                            <option value="0" <?php echo empty($antiFraudCfg['block_suspicious_rewards']) ? 'selected' : ''; ?>><?= e(t('admin.settings.nie_tylko_loguj')) ?></option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.wstrzymanie_wypat_przy_ryzyku')) ?></label>
                    <div class="zs-setting-control">
                        <select name="snajper[anti_fraud][hold_payouts_on_high_risk]" class="zs-setting-input">
                            <option value="1" <?php echo !empty($antiFraudCfg['hold_payouts_on_high_risk']) ? 'selected' : ''; ?>><?= e(t('admin.settings.tak_wstrzymuj')) ?></option>
                            <option value="0" <?php echo empty($antiFraudCfg['hold_payouts_on_high_risk']) ? 'selected' : ''; ?>><?= e(t('admin.settings.nie')) ?></option>
                        </select>
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.poziom_ryzyka_wstrzymujacy_wypate_0100')) ?></label>
                    <div class="zs-setting-control">
                        <input type="number" name="snajper[sensitivity][risk_score_hold_payout]" value="<?php echo e((string)($sens['risk_score_hold_payout'] ?? 80)); ?>" class="zs-setting-input">
                    </div>
                </div>
            </div>

            <h3><?= e(t('admin.settings.poziomy_kontroli')) ?></h3>
            <div class="zs-settings-grid">
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.poziom_ryzyka_pokazujacy_ostrzezenie_0100')) ?></label>
                    <div class="zs-setting-control">
                        <input type="number" name="snajper[sensitivity][risk_score_warn]" value="<?php echo e((string)($sens['risk_score_warn'] ?? 60)); ?>" class="zs-setting-input">
                    </div>
                </div>
                <div class="zs-setting-item">
                    <label class="zs-setting-label"><?= e(t('admin.settings.maksymalna_liczba_bonusow_dziennie')) ?></label>
                    <div class="zs-setting-control">
                        <input type="number" name="snajper[sensitivity][max_user_daily_bonus_events]" value="<?php echo e((string)($sens['max_user_daily_bonus_events'] ?? 40)); ?>" class="zs-setting-input">
                    </div>
                </div>
            </div>

            <div class="zs-settings-actions">
                <button type="submit" class="btn-red"><?= e(t('admin.settings.zapisz_ustawienia_antyfraudu')) ?></button>
            </div>
        </form>
    </section>

    <!-- 5. PROGRAM TALENT -->
    <section class="zs-settings-section zs-talent-operator" id="program-talent">
        <div class="zs-talent-section-head">
            <div>
                <p class="kicker"><?= e(t('admin.settings.program_aktywnosci')) ?></p>
                <h2><?= e(t('referral.landing.kicker')) ?></h2>
                <p><?= e(t('admin.settings.ustal_nagrody_tt_dla_osmiu_dziaan_ktore_system_potrafi_7190d680')) ?></p>
            </div>
            <div class="zs-talent-summary" aria-label="<?= e(t('admin.settings.podsumowanie_regu_programu_talent')) ?>">
                <div><strong><?php echo $activeTalentRuleCount; ?></strong><span><?= e(t('admin.settings.aktywnych')) ?></span></div>
                <div><strong><?php echo $talentRuleCount; ?></strong><span><?= e(t('admin.settings.wszystkich_regu')) ?></span></div>
            </div>
        </div>

        <div class="zs-talent-presence-note">
            <?php echo zs_icon('shield'); ?>
            <div>
                <strong><?= e(t('admin.settings.nagrody_trafiaja_tylko_do_rozpoznanego_uzytkownika')) ?></strong>
                <p><?= e(t('admin.settings.aktywna_wizyta_dzienna_wymaga_biezacego_sygnau_obecnosc_8021fde9')) ?></p>
            </div>
        </div>

        <section class="zs-talent-group zs-referral-admin" id="talent-promotion" aria-labelledby="talent-promotion-title">
            <div class="zs-talent-group-head">
                <?php echo zs_icon('share'); ?>
                <div>
                    <p class="kicker"><?= e(t('admin.settings.promocja_aplikacji')) ?></p>
                    <h3 id="talent-promotion-title"><?= e(t('admin.settings.bonus_za_instalacje_i_polecenie')) ?></h3>
                    <p><?= e(t('admin.settings.kontrolowana_promocja_nad_istniejacym_talentem_kwota_je_993d3d5a')) ?></p>
                </div>
                <span class="zs-talent-state<?= !empty($referralPromotion['is_promoted']) ? ' is-active' : '' ?>"><?= e(t(!empty($referralPromotion['is_promoted']) ? 'referral.promoted' : 'admin.payments.wyaczone')) ?></span>
            </div>

            <div class="zs-referral-admin-stats">
                <article><strong><?= (int)($referralCounts['mail_queued'] ?? 0) + (int)($referralCounts['sent'] ?? 0) + (int)($referralCounts['link_opened'] ?? 0) + (int)($referralCounts['installed'] ?? 0) + (int)($referralCounts['registered'] ?? 0) ?></strong><span><?= e(t('admin.settings.aktywnych_zaproszen')) ?></span></article>
                <article><strong><?= (int)($referralCounts['reward_queued'] ?? 0) + (int)($referralCounts['rewarded'] ?? 0) ?></strong><span><?= e(t('admin.settings.skutecznych_polecen')) ?></span></article>
                <article><strong><?= (int)($referralCounts['mail_dead_letter'] ?? 0) ?></strong><span><?= e(t('admin.settings.wiadomosci_wymagajacych_uwagi')) ?></span></article>
            </div>

            <form action="/admin/settings/talent-promotion" method="POST" class="zs-talent-form zs-referral-promotion-form">
                <?php echo csrf_field(); ?>
                <div class="zs-talent-rule-card is-active">
                    <div class="zs-talent-rule-controls">
                        <label>
                            <span><?= e(t('admin.settings.nagroda_dla_kazdej_strony')) ?></span>
                            <div class="zs-input-with-unit"><input type="number" min="1" max="1000000" step="1" required name="promotion[reward_points]" value="<?= e((string)($referralPromotion['reward_points'] ?? 1000)) ?>"><b>TT</b></div>
                        </label>
                        <label>
                            <span><?= e(t('admin.settings.aktywne_zaproszenia_osoba')) ?></span>
                            <div class="zs-input-with-unit"><input type="number" min="1" max="100" step="1" required name="promotion[active_invitation_limit]" value="<?= e((string)($referralPromotion['active_invitation_limit'] ?? 3)) ?>"><b><?= e(t('admin.settings.unit_items')) ?></b></div>
                        </label>
                        <label>
                            <span><?= e(t('admin.settings.skuteczne_polecenia_osoba')) ?></span>
                            <div class="zs-input-with-unit"><input type="number" min="1" max="100" step="1" required name="promotion[successful_referral_limit]" value="<?= e((string)($referralPromotion['successful_referral_limit'] ?? 3)) ?>"><b><?= e(t('admin.settings.unit_items')) ?></b></div>
                        </label>
                        <label>
                            <span><?= e(t('admin.settings.waznosc_zaproszenia')) ?></span>
                            <div class="zs-input-with-unit"><input type="number" min="1" max="365" step="1" required name="promotion[invitation_valid_days]" value="<?= e((string)($referralPromotion['invitation_valid_days'] ?? 30)) ?>"><b><?= e(t('admin.settings.dni')) ?></b></div>
                        </label>
                        <label><span><?= e(t('admin.settings.promuj_od')) ?></span><input type="datetime-local" required name="promotion[starts_at]" value="<?= e($promotionDateInput($referralPromotion['starts_at'] ?? null)) ?>"></label>
                        <label><span><?= e(t('admin.settings.promuj_do')) ?></span><input type="datetime-local" name="promotion[ends_at]" value="<?= e($promotionDateInput($referralPromotion['ends_at'] ?? null)) ?>"><small><?= e(t('admin.settings.puste_pole_oznacza_brak_daty_koncowej')) ?></small></label>
                        <label class="zs-talent-switch">
                            <input type="checkbox" name="promotion[is_promoted]" value="1" <?= !empty($referralPromotion['is_promoted']) ? 'checked' : '' ?>>
                            <span class="zs-talent-switch-track" aria-hidden="true"><i></i></span>
                            <span><?= e(t('admin.settings.promuj_w_portfelu_i_na_stronie_jak_zarabiac')) ?></span>
                        </label>
                    </div>
                </div>
                <div class="zs-critical-confirmation">
                    <?php echo zs_icon('shield'); ?>
                    <div class="zs-critical-confirmation-copy"><strong><?= e(t('admin.settings.zmiana_kontrolowana_przez_3dors')) ?></strong><p><?= e(t('admin.settings.nowa_wartosc_obejmie_wyacznie_zaproszenia_utworzone_po_zapisie')) ?></p></div>
                    <label><span><?= e(t('admin.ai.haso_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password"></label>
                    <button type="submit" class="btn-red"><?= e(t('admin.settings.zapisz_promocje')) ?></button>
                </div>
            </form>

            <div class="zs-referral-admin-history-head">
                <h4><?= e(t('admin.settings.ostatnie_zaproszenia')) ?></h4>
                <p><?= e(t('admin.settings.historia_pokazuje_kwote_zapisana_przy_wysaniu_stan_real_a08d7da7')) ?></p>
            </div>
            <?php if ($referralRecent !== []): ?>
                <div class="table-wrap zs-referral-admin-table">
                    <table>
                        <thead><tr><th><?= e(t('wallet.history.table.date')) ?></th><th><?= e(t('admin.settings.polecajacy')) ?></th><th><?= e(t('admin.settings.zaproszony_e_mail')) ?></th><th><?= e(t('admin.settings.zapisana_kwota')) ?></th><th><?= e(t('wallet.history.table.status')) ?></th><th><?= e(t('admin.settings.poczta')) ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($referralRecent as $invitation): ?>
                            <tr>
                                <td><?= e((string)$invitation['created_at']) ?></td>
                                <td><?= e((string)$invitation['inviter_email']) ?></td>
                                <td><?= e((string)$invitation['invited_email']) ?></td>
                                <td><strong><?= number_format((int)$invitation['reward_points'], 0, ',', ' ') ?> TT</strong></td>
                                <td><?= e(match((string)$invitation['status']) { 'mail_queued'=>t('admin.mails.wysyanie'), 'sent'=>t('admin.settings.wysane'), 'link_opened'=>t('admin.settings.referral_link_opened'), 'installed'=>t('admin.settings.referral_app_installed'), 'registered'=>t('admin.settings.konto_zaozone'), 'reward_queued'=>t('admin.settings.referral_reward_queued'), 'rewarded'=>t('admin.settings.referral_rewarded'), default=>t('admin.ai.zakonczone') }) ?></td>
                                <td><?= e(match((string)($invitation['mail_status'] ?? '')) { 'queued'=>t('admin.settings.oczekuje_na_wysanie'), 'sent'=>t('admin.settings.dostarczone_do_wysyki'), 'dead_letter'=>t('admin.settings.referral_retry_required'), default=>'—' }) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="zs-referral-admin-empty">
                    <strong><?= e(t('admin.settings.brak_zaproszen_do_wyswietlenia')) ?></strong>
                    <span><?= e(t('admin.settings.pierwsze_zaproszenie_pojawi_sie_tutaj_po_wysaniu_go_prz_cfbb65be')) ?></span>
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
                                    <span class="zs-talent-state<?php echo $isActive ? ' is-active' : ''; ?>" data-talent-state><?php echo e(t($isActive ? 'admin.settings.status_active_feminine' : 'admin.settings.wyaczona_2')); ?></span>
                                </div>

                                <div class="zs-talent-rule-controls">
                                    <label for="<?php echo e($fieldId); ?>-points">
                                        <span><?= e(t('admin.settings.punkty_talent')) ?></span>
                                        <div class="zs-input-with-unit">
                                            <input id="<?php echo e($fieldId); ?>-points" type="number" min="0" max="1000000" step="1" required name="rules[<?php echo e($ruleType); ?>][points]" value="<?php echo e((string)$r['points_amount']); ?>">
                                            <b>TT</b>
                                        </div>
                                    </label>
                                    <?php if ($isResponsePublicationRule): ?>
                                    <label for="<?php echo e($fieldId); ?>-deposit">
                                        <span><?= e(t('admin.settings.kaucja_przy_wysaniu')) ?></span>
                                        <div class="zs-input-with-unit">
                                            <input id="<?php echo e($fieldId); ?>-deposit" type="number" min="0" max="1000000" step="1" required name="rules[<?php echo e($ruleType); ?>][submission_deposit_points]" value="<?php echo e((string)($r['submission_deposit_points'] ?? 0)); ?>">
                                            <b>TT</b>
                                        </div>
                                        <small><?= e(t('admin.settings.0_wyacza_kaucje_pobieramy_ja_tylko_raz_przy_wysaniu_po_b36b5ad9')) ?></small>
                                    </label>
                                    <?php endif; ?>
                                    <input type="hidden" name="rules[<?php echo e($ruleType); ?>][money]" value="0">
                                    <?php if ($hasDailyLimit): ?>
                                      <label for="<?php echo e($fieldId); ?>-limit"><span><?= e(t('admin.settings.maksymalnie_dziennie')) ?></span><div class="zs-input-with-unit"><input id="<?php echo e($fieldId); ?>-limit" type="number" min="0" max="1000" step="1" required name="rules[<?php echo e($ruleType); ?>][limit]" value="<?php echo e((string)$r['daily_limit']); ?>"><b><?= e(t('admin.settings.tekstow')) ?></b></div><small><?= e(t('admin.settings.0_oznacza_brak_dodatkowego_limitu')) ?></small></label>
                                    <?php else: ?>
                                      <input type="hidden" name="rules[<?php echo e($ruleType); ?>][limit]" value="<?= $ruleType === 'day_visit_bonus' ? '1' : '0' ?>">
                                    <?php endif; ?>
                                    <?php if ($isResponsePublicationRule): ?><div class="zs-talent-tt-only"><strong><?= e(t('admin.settings.nagroda_i_kaucja_sa_wyacznie_w_tt')) ?></strong><span><?= e(t('admin.settings.odrzucenie_oznacza_przepadek_kaucji_a_poprawka_tej_same_91351cf8')) ?></span></div><?php endif; ?>
                                    <label class="zs-talent-switch" for="<?php echo e($fieldId); ?>-active">
                                        <input type="hidden" name="rules[<?php echo e($ruleType); ?>][exists]" value="1">
                                        <input id="<?php echo e($fieldId); ?>-active" type="checkbox" name="rules[<?php echo e($ruleType); ?>][active]" <?php echo $isActive ? 'checked' : ''; ?> data-talent-toggle>
                                        <span class="zs-talent-switch-track" aria-hidden="true"><i></i></span>
                                        <span><?= e(t('admin.settings.przyznawaj_te_nagrode')) ?></span>
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
                    <strong><?= e(t('admin.settings.potwierdz_zmiane_zasad_nagradzania')) ?></strong>
                    <p><?= e(t('admin.settings.to_ustawienie_wpywa_na_salda_uzytkownikow_3dors_zapisze_6f7d5b0e')) ?></p>
                </div>
                <label for="talent-critical-password">
                    <span><?= e(t('admin.ai.haso_administratora')) ?></span>
                    <input id="talent-critical-password" type="password" name="critical_password" required autocomplete="current-password">
                </label>
                <button type="submit" class="btn-red"><?= e(t('admin.settings.zapisz_program_talent')) ?></button>
            </div>
        </form>
    </section>

    <!-- POZOSTAŁE -->
    <?php if (!empty($settingsByGroup['other'])): ?>
    <section class="zs-settings-section zs-operator-settings-section">
        <div class="zs-operator-section-head">
            <div><p class="kicker"><?= e(t('admin.settings.ustawienia_dodatkowe')) ?></p><h2><?= e(t('admin.settings.pozostae')) ?></h2><p><?= e(t('admin.settings.parametry_pomocnicze_ktore_nie_naleza_do_wyspecjalizowa_e1e27c2e')) ?></p></div>
            <span><?= e(t('admin.settings.zaawansowane')) ?></span>
        </div>
        <form action="/admin/settings" method="POST">
            <?php echo csrf_field(); ?>
            <label class="zs-setting-label zs-critical-password"><span><?= e(t('admin.settings.potwierdz_hasem_administratora')) ?></span><input type="password" name="critical_password" required autocomplete="current-password" class="zs-setting-input" placeholder="<?= e(t('admin.ai.haso_chroniace_zmiane')) ?>"></label>
            <div class="zs-settings-grid">
                <?php foreach ($settingsByGroup['other'] as $s): ?>
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
                <button type="submit" class="btn-red"><?= e(t('admin.settings.zapisz_pozostae')) ?></button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <div class="admin-actions editorial-note">
        <a href="/admin" class="btn btn-secondary"><?= e(t('admin.settings.powrot_do_dashboardu')) ?></a>
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
            state.textContent = toggle.checked ? <?= json_encode(t('common.active'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> : <?= json_encode(t('common.inactive'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        };

        toggle.addEventListener('change', refreshState);
        refreshState();
    });
});
</script>
