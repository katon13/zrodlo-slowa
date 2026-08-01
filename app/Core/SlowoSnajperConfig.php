<?php
namespace App\Core;

final class SlowoSnajperConfig
{
    private array $config;

    public function __construct(private readonly string $rootPath)
    {
        $this->config = $this->loadFromFile();
    }

    public static function fromRoot(string $rootPath): self
    {
        return new self($rootPath);
    }

    public function all(): array
    {
        return $this->config;
    }

    public function enabled(): bool
    {
        return $this->bool('enabled', true);
    }

    public function strictMode(): bool
    {
        return $this->bool('strict_mode', true);
    }

    public function auditEnabled(): bool
    {
        return $this->bool('audit_enabled', true);
    }

    public function limit(string $key, int $fallback = 50, int $hardMax = 500): int
    {
        if (!$this->enabled()) {
            return min($hardMax, max(1, $fallback));
        }
        $value = (int)($this->config['limits'][$key] ?? $fallback);
        return max(1, min($hardMax, $value));
    }

    public function page(mixed $raw): int
    {
        return max(1, (int)$raw);
    }

    public function offset(int $page, int $limit): int
    {
        return max(0, ($page - 1) * $limit);
    }

    public function antiHeavyFlag(string $key, bool $fallback = false): bool
    {
        return $this->bool('anti_heavy_actions.' . $key, $fallback);
    }

    public function antiFraudFlag(string $key, bool $fallback = true): bool
    {
        return $this->bool('anti_fraud.' . $key, $fallback);
    }

    public function uiFlag(string $key, bool $fallback = true): bool
    {
        return $this->bool('ui.' . $key, $fallback);
    }

    public function sensitivity(string $key, int $fallback): int
    {
        return (int)($this->config['sensitivity'][$key] ?? $fallback);
    }

    public function earningsWorkerEnabled(): bool
    {
        return $this->bool('earnings_worker.enabled', true);
    }

    public function earningsWakeOnEvent(): bool
    {
        return $this->bool('earnings_worker.wake_on_event', true);
    }

    public function earningsSafetySweepSeconds(): int
    {
        return $this->bounded('earnings_worker.safety_sweep_seconds', 60, 30, 300);
    }

    public function earningsFallbackPollSeconds(): int
    {
        return $this->bounded('earnings_worker.fallback_poll_seconds', 60, 30, 300);
    }

    public function earningsBatchLimit(): int
    {
        return $this->bounded('earnings_worker.batch_limit', 25, 1, 100);
    }

    public function earningsMaxJobLatencySeconds(): int
    {
        return $this->bounded('earnings_worker.max_job_latency_seconds', 120, 30, 900);
    }

    public function earningsHeartbeatSeconds(): int
    {
        return $this->bounded('earnings_worker.heartbeat_seconds', 30, 10, 120);
    }

    public function earningsIdleDatabasePolling(): bool
    {
        return $this->bool('earnings_worker.idle_database_polling', false);
    }

    public function earningsPresenceEnabled(): bool
    {
        return $this->bool('earnings_worker.presence.enabled', true);
    }

    public function earningsPresenceVisibleOnly(): bool
    {
        return $this->bool('earnings_worker.presence.visible_tab_only', true);
    }

    public function earningsPresencePingSeconds(): int
    {
        return $this->bounded('earnings_worker.presence.ping_seconds', 60, 30, 300);
    }

    public function earningsPresenceTtlSeconds(): int
    {
        $minimum = $this->earningsPresencePingSeconds() + 10;
        return $this->bounded('earnings_worker.presence.ttl_seconds', 90, $minimum, 600);
    }

    public function earningsRequiresPresence(string $activityType): bool
    {
        $types = $this->config['earnings_worker']['presence']['required_activity_types'] ?? ['day_visit_bonus'];
        if (!is_array($types)) {
            return $activityType === 'day_visit_bonus';
        }
        return in_array($activityType, array_map('strval', $types), true);
    }

    public function articleReadProofEnabled(): bool
    {
        return $this->bool('earnings_worker.article_read_proof.enabled', true);
    }

    public function articleReadMinimumVisibleSeconds(): int
    {
        return $this->bounded('earnings_worker.article_read_proof.min_visible_seconds', 30, 5, 300);
    }

    public function articleReadMinimumProgressPercent(): int
    {
        return $this->bounded('earnings_worker.article_read_proof.min_progress_percent', 60, 25, 100);
    }

    public function articleReadProofTtlSeconds(): int
    {
        return $this->bounded('earnings_worker.article_read_proof.proof_ttl_seconds', 1800, 300, 7200);
    }

    public function saveFromAdmin(array $payload): void
    {
        $next = $this->config;
        if (array_key_exists('enabled', $payload)) {
            $next['enabled'] = $this->postedBool($payload, 'enabled');
        }
        if (array_key_exists('strict_mode', $payload)) {
            $next['strict_mode'] = $this->postedBool($payload, 'strict_mode');
        }
        if (array_key_exists('audit_enabled', $payload)) {
            $next['audit_enabled'] = $this->postedBool($payload, 'audit_enabled');
        }

        foreach (($payload['limits'] ?? []) as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_]/i', '', (string)$key);
            if ($cleanKey === '') {
                continue;
            }
            $next['limits'][$cleanKey] = max(1, min(500, (int)$value));
        }

        foreach (($payload['anti_heavy_actions'] ?? []) as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_]/i', '', (string)$key);
            if ($cleanKey === '') {
                continue;
            }
            $next['anti_heavy_actions'][$cleanKey] = in_array((string)$value, ['1', 'on', 'true', 'yes'], true);
        }

        foreach (($payload['sensitivity'] ?? []) as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_]/i', '', (string)$key);
            if ($cleanKey === '') {
                continue;
            }
            $next['sensitivity'][$cleanKey] = max(0, min(100000, (int)$value));
        }

        foreach (($payload['anti_fraud'] ?? []) as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_]/i', '', (string)$key);
            if ($cleanKey === '') {
                continue;
            }
            $next['anti_fraud'][$cleanKey] = in_array((string)$value, ['1', 'on', 'true', 'yes'], true);
        }

        foreach (($payload['roles'] ?? []) as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_]/i', '', (string)$key);
            if ($cleanKey === '') {
                continue;
            }
            $next['roles'][$cleanKey] = in_array((string)$value, ['1', 'on', 'true', 'yes'], true);
        }

        foreach (($payload['ui'] ?? []) as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_]/i', '', (string)$key);
            if ($cleanKey === '') {
                continue;
            }
            $next['ui'][$cleanKey] = in_array((string)$value, ['1', 'on', 'true', 'yes'], true);
        }

        if (isset($payload['earnings_worker']) && is_array($payload['earnings_worker'])) {
            $worker = $payload['earnings_worker'];
            foreach (['enabled', 'wake_on_event', 'idle_database_polling'] as $key) {
                if (array_key_exists($key, $worker)) {
                    $next['earnings_worker'][$key] = $this->postedBool($worker, $key);
                }
            }
            $bounds = [
                'safety_sweep_seconds' => [30, 300],
                'fallback_poll_seconds' => [30, 300],
                'batch_limit' => [1, 100],
                'max_job_latency_seconds' => [30, 900],
                'heartbeat_seconds' => [10, 120],
            ];
            foreach ($bounds as $key => [$minimum, $maximum]) {
                if (array_key_exists($key, $worker)) {
                    $next['earnings_worker'][$key] = max($minimum, min($maximum, (int)$worker[$key]));
                }
            }
            if (isset($worker['presence']) && is_array($worker['presence'])) {
                $presence = $worker['presence'];
                foreach (['enabled', 'visible_tab_only'] as $key) {
                    if (array_key_exists($key, $presence)) {
                        $next['earnings_worker']['presence'][$key] = $this->postedBool($presence, $key);
                    }
                }
                if (array_key_exists('ping_seconds', $presence)) {
                    $next['earnings_worker']['presence']['ping_seconds'] = max(30, min(300, (int)$presence['ping_seconds']));
                }
                if (array_key_exists('ttl_seconds', $presence)) {
                    $ping = (int)($next['earnings_worker']['presence']['ping_seconds'] ?? 60);
                    $next['earnings_worker']['presence']['ttl_seconds'] = max($ping + 10, min(600, (int)$presence['ttl_seconds']));
                }
            }
            if (isset($worker['article_read_proof']) && is_array($worker['article_read_proof'])) {
                $proof = $worker['article_read_proof'];
                if (array_key_exists('enabled', $proof)) {
                    $next['earnings_worker']['article_read_proof']['enabled'] = $this->postedBool($proof, 'enabled');
                }
                foreach ([
                    'min_visible_seconds' => [5, 300],
                    'min_progress_percent' => [25, 100],
                    'proof_ttl_seconds' => [300, 7200],
                ] as $key => [$minimum, $maximum]) {
                    if (array_key_exists($key, $proof)) {
                        $next['earnings_worker']['article_read_proof'][$key] = max(
                            $minimum,
                            min($maximum, (int)$proof[$key])
                        );
                    }
                }
            }
        }

        $path = $this->path();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Nie udało się zapisać konfiguracji SNAJPERA SŁOWA.');
        }
        $this->config = $next;
    }

    private function loadFromFile(): array
    {
        $default = $this->defaults();
        $path = $this->path();
        if (!is_file($path)) {
            return $default;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $default;
        }
        return array_replace_recursive($default, $decoded);
    }

    private function path(): string
    {
        return $this->rootPath . '/config/slowo_snajper.json';
    }

    private function defaults(): array
    {
        return [
            'enabled' => true,
            'strict_mode' => true,
            'audit_enabled' => true,
            'limits' => [
                'public_articles' => 20,
                'article_media' => 12,
                'author_articles' => 30,
                'wallet_transactions' => 20,
                'wallet_payouts' => 10,
                'bonus_notifications' => 12,
                'admin_articles' => 50,
                'admin_users' => 50,
                'admin_surveys' => 50,
                'admin_campaigns' => 50,
                'admin_payouts' => 50,
                'admin_ledger' => 100,
                'admin_reports' => 100,
                'admin_roles_users' => 50,
                'role_panel_items' => 50,
                'admin_fraud_events' => 50,
                'admin_fraud_users' => 50,
                'fraud_scan_users' => 200,
                'ui_bonus_rows' => 20,
                'ui_admin_cards' => 50,
            ],
            'anti_heavy_actions' => [
                'allow_full_table_admin_lists' => false,
                'allow_hard_user_clean' => false,
                'allow_database_reset_from_admin' => false,
            ],
            'sensitivity' => [
                'risk_score_warn' => 60,
                'risk_score_hold_payout' => 80,
                'max_user_daily_bonus_events' => 40,
                'max_same_ad_reward_per_day' => 1,
                'min_ad_watch_seconds' => 15,
                'min_survey_answer_seconds' => 8,
                'max_fast_actions_per_minute' => 8,
                'new_account_hours' => 24,
                'new_account_bonus_warn_count' => 10,
            ],
            'roles' => [
                'editorial_panels_enabled' => true,
                'admin_role_assignment_enabled' => true,
                'higher_roles_require_verified_email' => true,
                'higher_roles_require_2fa' => true,
                'stage2_enforce_panel_scope' => true,
                'stage3_enforce_high_role_security' => true,
            ],
            'auth' => [
                'login_2fa_challenge_enabled' => true,
                'login_2fa_pending_ttl_seconds' => 600,
                'log_login_events' => true,
            ],
            'install_reset' => [
                'allow_cli_fresh_install' => true,
                'allow_cli_database_reset' => true,
                'require_confirm_flag' => true,
                'block_in_production' => true,
                'default_keep_admin' => true,
            ],
            'anti_fraud' => [
                'enabled' => true,
                'log_events' => true,
                'block_suspicious_rewards' => true,
                'hold_payouts_on_high_risk' => true,
                'scan_enabled' => true,
                'scan_limit' => 200,
            ],
            'earnings_worker' => [
                'enabled' => true,
                'wake_on_event' => true,
                'safety_sweep_seconds' => 60,
                'idle_database_polling' => false,
                'fallback_poll_seconds' => 60,
                'batch_limit' => 25,
                'max_job_latency_seconds' => 120,
                'heartbeat_seconds' => 30,
                'presence' => [
                    'enabled' => true,
                    'ping_seconds' => 60,
                    'ttl_seconds' => 90,
                    'visible_tab_only' => true,
                    'required_activity_types' => ['day_visit_bonus'],
                ],
                'article_read_proof' => [
                    'enabled' => true,
                    'min_visible_seconds' => 30,
                    'min_progress_percent' => 60,
                    'proof_ttl_seconds' => 1800,
                ],
            ],
            'ui' => [
                'enabled' => true,
                'icons_enabled' => true,
                'compact_bonus_rows' => true,
                'unified_admin_cards' => true,
                'unified_status_badges' => true,
                'lightweight_css_only' => true,
            ],
        ];
    }

    private function bool(string $path, bool $fallback): bool
    {
        $value = $this->config;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $fallback;
            }
            $value = $value[$part];
        }
        return in_array((string)$value, ['1', 'true', 'on', 'yes'], true) || $value === true || $value === 1;
    }

    private function bounded(string $path, int $fallback, int $minimum, int $maximum): int
    {
        $value = $this->config;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return max($minimum, min($maximum, $fallback));
            }
            $value = $value[$part];
        }
        return max($minimum, min($maximum, (int)$value));
    }

    private function postedBool(array $payload, string $key): bool
    {
        return isset($payload[$key]) && in_array((string)$payload[$key], ['1', 'on', 'true', 'yes'], true);
    }
}
