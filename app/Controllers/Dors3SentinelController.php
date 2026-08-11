<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Jobs\Dors3SentinelArchiveJobHandler;
use App\Security\Dors3\SecurityId;
use App\Services\Dors3MobileService;
use App\Services\Dors3OperatorPresenter;
use App\Services\Dors3SentinelAlertService;
use App\Services\Dors3SentinelService;
use App\Services\Dors3UiText;
use App\Services\DurableJobQueue;
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
            static function (array $alert) use ($language): array {
                $alert['stages'] = array_map(
                    static fn(array $event): array => Dors3OperatorPresenter::event($event, $language),
                    is_array($alert['stages'] ?? null) ? $alert['stages'] : [],
                );
                return Dors3OperatorPresenter::alert($alert, $language);
            },
            $dashboard['alerts'],
        );
        $dashboard['ended_sessions'] = array_map(
            static fn(array $event): array => Dors3OperatorPresenter::event($event, $language),
            $dashboard['ended_sessions'],
        );

        return $this->view('admin/dors3_sentinel', [
            'title' => Dors3UiText::get('sentinel.page_title', [], $language),
            'sentinel' => $dashboard,
            'ui_language' => $language,
            'sentinel_filters' => Dors3SentinelService::FILTERS,
            'sentinel_views' => Dors3SentinelService::VIEWS,
            'sentinel_resolution_reasons' => Dors3SentinelAlertService::RESOLUTION_REASONS,
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

    public function archive(): never
    {
        $adminId = $this->requireAdmin();
        $language = $this->language();
        $cutoff = trim((string)($_POST['cutoff_date'] ?? ''));
        try {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff, new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d') !== $cutoff || $date > new \DateTimeImmutable('-30 days', new \DateTimeZone('UTC'))) {
                throw new \InvalidArgumentException(Dors3UiText::get('sentinel.archive_cutoff_invalid', [], $language));
            }
            $authorizationId = $this->authorizeCriticalOperation(
                $adminId,
                'sentinel.logs.archive',
                'security_event_archive',
                $cutoff,
                ['cutoff_date' => $cutoff],
            );
            $requestPublicId = SecurityId::uuid();
            (new DurableJobQueue($this->app->db, $this->app->queueSignals))->enqueue(
                Dors3SentinelArchiveJobHandler::QUEUE,
                Dors3SentinelArchiveJobHandler::JOB_TYPE,
                [
                    'cutoff_date' => $cutoff,
                    'actor_id' => $adminId,
                    'authorization_public_id' => $authorizationId,
                    'request_public_id' => $requestPublicId,
                    'sequence' => 1,
                ],
                'sentinel-archive:' . $requestPublicId . ':chunk:1',
                -20,
                5,
                'automatic',
                $adminId,
            );
            $this->securityEvents()->record(
                $adminId,
                'sentinel.archive.queued',
                'success',
                'low',
                'security_event_archive',
                $requestPublicId,
                null,
                [
                    'cutoff_date' => $cutoff,
                    'queue' => Dors3SentinelArchiveJobHandler::QUEUE,
                ],
                'protected_archive_queued',
                null,
                [
                    'operation' => 'sentinel.logs.archive',
                    'authorization_public_id' => $authorizationId,
                ],
            );
            $this->app->session->flash('success', Dors3UiText::get('sentinel.archive_queued', [], $language));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->safeError(
                $error,
                Dors3UiText::get('sentinel.archive_failed', [], $language),
                'dors3_sentinel_archive',
            ));
        }
        redirect('/admin/security/sentinel?lang=' . rawurlencode($language) . '&view=archive');
    }

    private function changeAlertStatus(string $targetStatus): never
    {
        $adminId = $this->requireAdmin();
        $language = $this->language();
        $alertPublicId = trim((string)($_GET['alert_public_id'] ?? ''));
        try {
            $before = $this->app->db->one(
                'SELECT status,severity FROM security_alerts WHERE public_id=:public_id',
                ['public_id' => $alertPublicId],
            );
            $service = new Dors3SentinelAlertService($this->app->db);
            $after = $targetStatus === 'acknowledged'
                ? $service->acknowledge($alertPublicId, $adminId)
                : $service->resolve(
                    $alertPublicId,
                    $adminId,
                    (string)($_POST['reason_code'] ?? ''),
                    (string)($_POST['note'] ?? ''),
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
        $view = in_array((string)($_POST['return_view'] ?? ''), Dors3SentinelService::VIEWS, true)
            ? (string)$_POST['return_view']
            : 'active';
        redirect('/admin/security/sentinel?lang=' . rawurlencode($language) . '&view=' . rawurlencode($view));
    }

    private function language(): string
    {
        $requested = strtolower(trim((string)($_REQUEST['lang'] ?? '')));
        if (in_array($requested, ['pl', 'en', 'de', 'fr', 'it', 'es'], true)) {
            return $requested;
        }
        $sessionLanguage = strtolower(trim((string)($this->app->session->language() ?? '')));
        return in_array($sessionLanguage, ['pl', 'en', 'de', 'fr', 'it', 'es'], true) ? $sessionLanguage : 'pl';
    }
}
