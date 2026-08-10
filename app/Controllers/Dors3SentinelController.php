<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Dors3MobileService;
use App\Services\Dors3OperatorPresenter;
use App\Services\Dors3SentinelAlertService;
use App\Services\Dors3SentinelService;
use App\Services\Dors3UiText;
use App\Services\SecretCipher;
use App\Services\WebAuthnFoundationService;

final class Dors3SentinelController extends BaseController
{
    public function index(): string
    {
        $adminId = $this->requireAdmin();
        $language = $this->language();
        $settings = $this->dors3Settings()->current();
        $webAuthn = (new WebAuthnFoundationService(
            $this->app->db,
            is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
        ))->status();
        $devices = [];
        $policies = [];
        if ($this->app->db->tableExists('security_mobile_devices')) {
            $mobile = new Dors3MobileService(
                $this->app->db,
                SecretCipher::fromEnvironment(),
                $this->securityEvents(),
                is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
            );
            $devices = $mobile->devices();
            $policies = $mobile->policies();
        }

        $sentinel = new Dors3SentinelService($this->app->db);
        $dashboard = $sentinel->dashboard(
            $adminId,
            $_GET,
            $sentinel->readiness($settings, $webAuthn, $policies, $devices),
        );
        $dashboard['events'] = array_map(
            static fn(array $event): array => Dors3OperatorPresenter::event($event, $language),
            $dashboard['events'],
        );
        $dashboard['alerts'] = array_map(
            static fn(array $event): array => Dors3OperatorPresenter::event($event, $language),
            $dashboard['alerts'],
        );

        return $this->view('admin/dors3_sentinel', [
            'title' => Dors3UiText::get('sentinel.page_title', [], $language),
            'sentinel' => $dashboard,
            'ui_language' => $language,
            'sentinel_filters' => Dors3SentinelService::FILTERS,
            'sentinel_alert_statuses' => Dors3SentinelService::ALERT_STATUSES,
        ]);
    }

    public function acknowledge(): never
    {
        $this->changeAlertStatus('acknowledged');
    }

    public function resolve(): never
    {
        $this->changeAlertStatus('resolved');
    }

    private function changeAlertStatus(string $targetStatus): never
    {
        $adminId = $this->requireAdmin();
        $language = $this->language();
        $alertPublicId = trim((string)($_GET['alert_public_id'] ?? ''));
        $reason = (string)($_POST['reason'] ?? '');
        try {
            $before = $this->app->db->one(
                'SELECT status,severity FROM security_alerts WHERE public_id=:public_id',
                ['public_id' => $alertPublicId],
            );
            $after = (new Dors3SentinelAlertService($this->app->db))->transition(
                $alertPublicId,
                $adminId,
                $targetStatus,
                $reason,
            );
            $this->securityEvents()->record(
                $adminId,
                'sentinel.alert.' . $targetStatus,
                'success',
                'low',
                'security_alert',
                $alertPublicId,
                $before,
                ['status' => (string)($after['status'] ?? $targetStatus)],
                'operator_alert_review',
                null,
                ['sentinel_observation_only' => true],
            );
            $this->app->session->flash(
                'success',
                Dors3UiText::get('sentinel.alert_transition_success', [], $language),
            );
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError(
                $error,
                Dors3UiText::get('sentinel.alert_transition_failed', [], $language),
                'dors3_sentinel_alert_transition',
            ));
        }
        redirect('/admin/security/sentinel?lang=' . rawurlencode($language));
    }

    private function language(): string
    {
        $requested = strtolower(trim((string)($_REQUEST['lang'] ?? '')));
        if (in_array($requested, ['pl', 'en'], true)) {
            return $requested;
        }
        $sessionLanguage = strtolower(trim((string)($this->app->session->language() ?? '')));
        return in_array($sessionLanguage, ['pl', 'en'], true) ? $sessionLanguage : 'pl';
    }
}
