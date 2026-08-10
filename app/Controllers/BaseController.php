<?php
namespace App\Controllers;

use App\Core\App;
use App\Security\Authorization\PermissionCatalog;

abstract class BaseController
{
    public function __construct(protected readonly App $app) {}

    protected function slowoSnajperConfig(): \App\Core\SlowoSnajperConfig
    {
        return \App\Core\SlowoSnajperConfig::fromRoot($this->app->rootPath);
    }

    protected function slowoSnajper(): \App\Services\SlowoSnajperService
    {
        return new \App\Services\SlowoSnajperService($this->app->db, $this->slowoSnajperConfig());
    }

    protected function earningsDispatcher(): \App\Services\EarningsJobDispatcher
    {
        return new \App\Services\EarningsJobDispatcher(
            $this->app->db,
            new \App\Services\DurableJobQueue($this->app->db),
            $this->app->queueSignals,
            $this->slowoSnajperConfig(),
        );
    }

    protected function talentService(): \App\Services\TalentService
    {
        return new \App\Services\TalentService(
            $this->app->db,
            new \App\Services\LedgerService(
                $this->app->db,
                new \App\Services\FinancialService($this->app->db)
            ),
            $this->earningsDispatcher(),
        );
    }

    protected function appReferralService(): \App\Services\AppReferralService
    {
        return new \App\Services\AppReferralService(
            $this->app->db,
            new \App\Services\MailService($this->app->db),
            $this->earningsDispatcher(),
            $this->app->queueSignals,
        );
    }

    protected function earningsPresenceService(): \App\Services\EarningsPresenceService
    {
        return new \App\Services\EarningsPresenceService(
            $this->app->valkey,
            $this->slowoSnajperConfig(),
            $this->earningsDispatcher(),
        );
    }

    protected function notificationOutboxDispatcher(): \App\Services\NotificationOutboxDispatcher
    {
        return new \App\Services\NotificationOutboxDispatcher(
            $this->app->db,
            new \App\Services\DurableJobQueue($this->app->db),
            $this->app->queueSignals,
            $this->slowoSnajperConfig(),
        );
    }

    protected function articleReadProofService(): \App\Services\ArticleReadProofService
    {
        return new \App\Services\ArticleReadProofService(
            $this->app->valkey,
            $this->slowoSnajperConfig(),
            $this->earningsDispatcher(),
        );
    }

    protected function dors3Settings(): \App\Services\Dors3SettingsService
    {
        return new \App\Services\Dors3SettingsService(
            $this->app->db,
            is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
        );
    }

    protected function securityEvents(): \App\Services\SecurityEventService
    {
        return new \App\Services\SecurityEventService($this->app->db);
    }

    protected function passwordStepUpAuthorizer(): \App\Services\PasswordStepUpAuthorizer
    {
        return new \App\Services\PasswordStepUpAuthorizer(
            $this->app->db,
            $this->dors3Settings(),
            $this->securityEvents(),
        );
    }

    protected function criticalOperationAuthorizer(): \App\Security\Dors3\CriticalOperationAuthorizerInterface
    {
        $settings = $this->dors3Settings()->current();
        if ((string)$settings['critical_step_up'] === 'fido2') {
            return new \App\Services\Fido2StepUpAuthorizer();
        }
        return $this->passwordStepUpAuthorizer();
    }

    protected function adminSessionPolicy(): \App\Services\AdminSessionPolicy
    {
        return new \App\Services\AdminSessionPolicy(
            $this->app->session,
            $this->dors3Settings(),
            $this->securityEvents(),
            $this->passwordStepUpAuthorizer(),
        );
    }

    /**
     * @param array<string, mixed> $details
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    protected function authorizeCriticalOperation(
        int $adminId,
        string $operation,
        string $resourceType,
        string $resourceId,
        array $details = [],
        ?array $before = null,
        ?array $after = null,
    ): string {
        if ($adminId !== (int)$this->app->session->userId()) {
            throw new \RuntimeException('Operacja krytyczna wymaga aktywnej sesji uprawnionego aktora.');
        }
        $password = (string)($_POST['critical_password'] ?? '');
        if ($password === '') {
            throw new \RuntimeException('Podaj aktualne hasło administratora, aby potwierdzić tę operację.');
        }
        $context = new \App\Security\Dors3\ApprovalContext(
            $operation,
            $adminId,
            $resourceType,
            $resourceId,
            $details,
            $before,
            $after,
        );
        $authorizer = $this->criticalOperationAuthorizer();
        $request = $authorizer->begin($context);
        $result = $authorizer->verify(new \App\Security\Dors3\ApprovalResponse($request, $password));
        if (!$result->approved) {
            throw new \RuntimeException('Operacja krytyczna nie została zatwierdzona.');
        }
        return $result->authorizationPublicId;
    }

    protected function view(string $name, array $data = []): string
    {
        if (!array_key_exists('flash_success', $data)) {
            $data['flash_success'] = $this->app->session->pullFlash('success');
        }
        if (!array_key_exists('flash_error', $data)) {
            $data['flash_error'] = $this->app->session->pullFlash('error');
        }
        if (!array_key_exists('flash_last_user_id', $data)) {
            $data['flash_last_user_id'] = $this->app->session->pullFlash('last_user_id');
        }
        if (!array_key_exists('live_earnings', $data)) {
            $data['live_earnings'] = [];
        }
        if (!array_key_exists('current_language', $data)) {
            // Język widoku wynika z jawnego URL-a (/pl, /de, ...), ?lang albo _lang w akcji.
            // Nie zapisujemy tu języka do sesji przy każdym renderze, bo pojedynczy błędny
            // request potrafił utrwalić DE/PL i przełączać cały serwis.
            $data['current_language'] = function_exists('public_language') ? \public_language() : (string)($this->app->config['languages']['default'] ?? 'pl');
        }
        if (!array_key_exists('earnings_presence', $data)) {
            $snajper = $this->slowoSnajperConfig();
            $data['earnings_presence'] = [
                'enabled' => $this->app->session->userId() !== null
                    && $snajper->earningsWorkerEnabled()
                    && $snajper->earningsPresenceEnabled(),
                'ping_seconds' => $snajper->earningsPresencePingSeconds(),
                'user_id' => $this->app->session->userId(),
            ];
        }
        if (!array_key_exists('tt_rate_label', $data)) {
            $paymentConfig = new \App\Services\PaymentRuntimeConfigService($this->app->db, $this->app->config['payments'] ?? []);
            $currencyService = new \App\Services\CurrencyRateService($this->app->cache);
            $lang = $data['current_language'] ?? 'pl';
            
            // Pobieramy preferencje użytkownika jeśli to możliwe
            $userId = $this->app->session->userId();
            $userCurrency = 'AUTO';
            if ($userId) {
                try {
                    $u = $this->app->db->one('SELECT display_currency FROM users WHERE id = :id', ['id' => $userId]);
                    if ($u) {
                        $userCurrency = $u['display_currency'] ?? 'AUTO';
                    }
                } catch (\Throwable) {
                    $userCurrency = 'AUTO';
                }
            }
            
            $effectiveCurrency = $currencyService->effectiveCurrency($lang, $userCurrency);
            $localValue = $currencyService->ttToLocalApprox(10, $lang, $userCurrency);
            
            $data['user_display_currency'] = $userCurrency;
            $data['effective_display_currency'] = $effectiveCurrency;
            $data['tt_rate_label'] = $paymentConfig->formatTtRateLabel($effectiveCurrency, $localValue);
        }
        if (!array_key_exists('menu_categories', $data)) {
            $lang = $data['current_language'] ?? 'pl';
            $data['menu_categories'] = $this->app->cache->remember("site_menu:{$lang}", 3600, function() {
                return (new \App\Services\CategoryService($this->app->db))->allForMenu();
            });
        }
        if (!array_key_exists('current_site', $data)) {
            $data['current_site'] = function_exists('public_site') ? \public_site() : [];
        }
        if (!array_key_exists('public_languages', $data)) {
            $data['public_languages'] = $this->app->cache->remember('site_languages:public', 86400, function() {
                return $this->app->config['languages']['public_enabled'] ?? ['pl'];
            });
        }
        if (!array_key_exists('language_labels', $data)) {
            $data['language_labels'] = $this->app->cache->remember('site_languages:labels', 86400, function() {
                return $this->app->config['languages']['labels'] ?? [];
            });
        }
        if (!array_key_exists('language_short_labels', $data)) {
            $data['language_short_labels'] = $this->app->cache->remember('site_languages:short_labels', 86400, function() {
                return $this->app->config['languages']['short_labels'] ?? [];
            });
        }
        if (!array_key_exists('language_brand_names', $data)) {
            $data['language_brand_names'] = $this->app->cache->remember('site_languages:brand_names', 86400, function() {
                return $this->app->config['languages']['brand_names'] ?? [];
            });
        }
        if (!array_key_exists('language_flag_codes', $data)) {
            $data['language_flag_codes'] = $this->app->cache->remember('site_languages:flag_codes', 86400, function() {
                return $this->app->config['languages']['flag_codes'] ?? [];
            });
        }
        if (!array_key_exists('public_sites', $data)) {
            $data['public_sites'] = $this->app->config['sites']['domains'] ?? [];
        }
        // Zawsze ładujemy podstawowe dane zalogowanego użytkownika, jeśli nie zostały przekazane
        $userId = $this->app->session->userId();
        if ($userId) {
            if (!isset($data['current_user_avatar']) || !isset($data['current_user_display_name'])) {
                try {
                    $u = $this->app->db->one('SELECT avatar_path, avatar_updated_at, display_name FROM users WHERE id = :id', ['id' => $userId]);
                    if ($u) {
                        $data['current_user_avatar'] = $data['current_user_avatar'] ?? $u['avatar_path'] ?? null;
                        $data['current_user_avatar_updated_at'] = $data['current_user_avatar_updated_at'] ?? $u['avatar_updated_at'] ?? null;
                        $data['current_user_display_name'] = $data['current_user_display_name'] ?? $u['display_name'] ?? '';
                        
                        // Synchronizacja z layoutem (stare nazwy zmiennych dla kompatybilności)
                        $data['navAvatarPath'] = $data['current_user_avatar'];
                        $data['navAvatarUpdatedAt'] = $data['current_user_avatar_updated_at'];
                    }
                } catch (\Throwable $e) {
                    // Cichy błąd, nie chcemy wywalić całej strony przez brak avatara
                }
            }
        }

        return $this->app->view->render($name, $data);
    }

    protected function isAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

    protected function json(array $data): never
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function safeError(\Throwable $error, string $fallback, string $context = 'request'): string
    {
        return (new \App\Services\ErrorReporter())->publicMessage($error, $fallback, $context);
    }


    protected function currentUserRoles(): array
    {
        $id = $this->app->session->userId();
        if (!$id) {
            return [];
        }
        try {
            $rows = $this->app->db->all('SELECT role FROM user_roles WHERE user_id=:id', ['id' => $id]);
            $roles = array_map(static fn(array $row): string => (string)$row['role'], $rows);
            $sessionRole = (string)$this->app->session->role();
            if ($sessionRole !== '') {
                $roles[] = $sessionRole;
            }
            return array_values(array_unique($roles));
        } catch (\Throwable) {
            $role = (string)$this->app->session->role();
            return $role !== '' ? [$role] : [];
        }
    }

    protected function requireAdminOrRoles(array $roles): int
    {
        $id = $this->requireAuth();
        $current = $this->currentUserRoles();
        if (in_array('admin', $current, true)) {
            $this->assertAdminSessionAccess($id);
            return $id;
        }
        if (array_intersect($roles, $current) !== []) {
            return $id;
        }
        http_response_code(403);
        echo $this->view('layouts/error', [
            'title' => 'Brak uprawnień',
            'message' => 'Ten kafelek SNAJPERA SŁOWA jest dostępny tylko dla przypisanej roli redakcyjnej albo administratora.',
        ]);
        exit;
    }

    protected function requirePermission(string $permission): int
    {
        $id = $this->requireAuth();
        if (!PermissionCatalog::allows($this->currentUserRoles(), $permission)) {
            http_response_code(403);
            echo $this->view('layouts/error', [
                'title' => 'Brak uprawnień',
                'message' => 'Ta operacja wymaga szczegółowego uprawnienia: ' . $permission . '.',
            ]);
            exit;
        }
        if (in_array('admin', $this->currentUserRoles(), true)) {
            $this->assertAdminSessionAccess($id);
        }
        if (PermissionCatalog::requiresStrongAuthentication($permission)) {
            $this->requireHighRoleSecurity($id, 'operacji ' . $permission);
        }
        return $id;
    }


    protected function requireHighRoleSecurity(int $userId, string $contextLabel = 'wysokiej roli'): void
    {
        try {
            (new \App\Services\AuthSecurityService($this->app->db, $this->slowoSnajperConfig()))->assertHighRoleReady($userId, $contextLabel);
        } catch (\Throwable $e) {
            http_response_code(403);
            echo $this->view('layouts/error', [
                'title' => 'Wymagane zabezpieczenia konta',
                'message' => $this->safeError($e, 'Konto nie spełnia wymagań bezpieczeństwa tej roli.', 'high_role_security'),
            ]);
            exit;
        }
    }

    protected function requireAuth(): int
    {
        $id = $this->app->session->userId();
        if (!$id) {
            $lang = function_exists('public_language') ? \public_language() : 'pl';
            redirect(function_exists('public_language_url') ? \public_language_url($lang, '/login') : '/login');
        }
        return $id;
    }

    protected function requireAdmin(): int
    {
        $id = $this->requireAuth();
        if ($this->app->session->role() !== 'admin') {
            http_response_code(403);
            echo $this->view('layouts/error', [
                'title' => 'Brak uprawnień',
                'message' => 'Ten ekran jest dostępny tylko dla administratora lub redakcji z odpowiednimi uprawnieniami.',
            ]);
            exit;
        }
        $this->assertAdminSessionAccess($id);
        return $id;
    }

    private function assertAdminSessionAccess(int $adminId): void
    {
        try {
            $this->adminSessionPolicy()->assertAccess($adminId);
        } catch (\App\Security\Dors3\AdminSessionLockedException) {
            $returnPath = (string)($_SERVER['REQUEST_URI'] ?? '/admin');
            redirect('/admin/security/unlock?return=' . urlencode($returnPath));
        } catch (\App\Security\Dors3\AdminSessionExpiredException $error) {
            $this->app->session->flash('error', $error->getMessage());
            redirect('/login');
        }
    }

}
