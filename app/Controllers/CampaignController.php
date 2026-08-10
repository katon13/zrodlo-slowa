<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\CampaignService;
use App\Services\FraudGuardService;

final class CampaignController extends BaseController
{
    private const VIEW_PROOF_SESSION_KEY = '_campaign_view_proofs';
    private const VIEW_PROOF_TTL_SECONDS = 600;

    public function index(): string
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $service = $this->service();
        return $this->view('campaigns/index', [
            'title' => t('campaign.index.title', $language),
            'campaigns' => $service->activeCampaigns(),
            'types' => $service->types(),
        ]);
    }

    public function show(): string
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $campaign = $this->service()->findActive((int)($_GET['id'] ?? 0));
        if ($campaign === null) {
            http_response_code(404);
            return $this->view('layouts/error', [
                'title' => t('campaign.unavailable.title', $language),
                'message' => t('campaign.unavailable.message', $language),
            ]);
        }

        $proof = null;
        $userId = $this->app->session->userId();
        if ($userId !== null && in_array((string)$campaign['type'], ['display_ad', 'ad_view'], true)) {
            $proof = $this->issueViewProof($userId, (int)$campaign['id']);
        }

        return $this->view('campaigns/show', [
            'title' => (string)$campaign['name'],
            'campaign' => $campaign,
            'types' => $this->service()->types(),
            'view_proof' => $proof,
        ]);
    }

    public function viewAd(): never
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $userId = $this->requireAuth();
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        try {
            $proof = $this->consumeViewProof($userId, $campaignId);
            $result = $this->service()->recordView(
                $userId,
                $campaignId,
                $proof['elapsed_seconds'],
                $proof['reference'],
            );
            $this->app->session->flash(
                'success',
                t($result !== null ? 'campaign.action.queued' : 'campaign.action.duplicate', $language),
            );
        } catch (\Throwable $error) {
            $this->app->session->flash(
                'error',
                $this->safeError($error, t('campaign.action.failed', $language), 'campaign_view'),
            );
        }
        redirect('/campaign?id=' . $campaignId);
    }

    public function clickAd(): never
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $userId = $this->requireAuth();
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $campaign = $this->service()->findActive($campaignId);
        try {
            $result = $this->service()->recordClick($userId, $campaignId);
            $this->app->session->flash(
                'success',
                t($result !== null ? 'campaign.action.queued' : 'campaign.action.duplicate', $language),
            );
            $url = $campaign['target_url'] ?? null;
            if (is_string($url) && preg_match('~^https?://~i', $url) === 1) {
                redirect($url);
            }
        } catch (\Throwable $error) {
            $this->app->session->flash(
                'error',
                $this->safeError($error, t('campaign.action.failed', $language), 'campaign_click'),
            );
        }
        redirect('/campaign?id=' . $campaignId);
    }

    public function sponsoredRead(): never
    {
        $this->notAvailable('recordSponsoredRead', 'campaign_sponsored_read');
    }

    public function ppv(): never
    {
        $this->notAvailable('recordPpv', 'campaign_ppv');
    }

    public function liveJoin(): never
    {
        $this->notAvailable('recordLiveJoin', 'campaign_live');
    }

    private function notAvailable(string $method, string $logContext): never
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $userId = $this->requireAuth();
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        try {
            $this->service()->{$method}($userId, $campaignId);
        } catch (\Throwable $error) {
            $this->app->session->flash(
                'error',
                $this->safeError($error, t('campaign.action.unavailable', $language), $logContext),
            );
        }
        redirect('/campaign?id=' . $campaignId);
    }

    /** @return array{token:string,min_seconds:int} */
    private function issueViewProof(int $userId, int $campaignId): array
    {
        $token = bin2hex(random_bytes(24));
        $proofs = $this->app->session->get(self::VIEW_PROOF_SESSION_KEY, []);
        $proofs = is_array($proofs) ? $proofs : [];
        $now = time();
        $proofs = array_filter(
            $proofs,
            static fn(mixed $proof): bool => is_array($proof) && (int)($proof['expires_at'] ?? 0) >= $now,
        );
        if (count($proofs) >= 10) {
            $proofs = array_slice($proofs, -9, null, true);
        }
        $proofs[$token] = [
            'user_id' => $userId,
            'campaign_id' => $campaignId,
            'issued_at' => $now,
            'expires_at' => $now + self::VIEW_PROOF_TTL_SECONDS,
        ];
        $this->app->session->set(self::VIEW_PROOF_SESSION_KEY, $proofs);
        return [
            'token' => $token,
            'min_seconds' => max(1, $this->slowoSnajperConfig()->sensitivity('min_ad_watch_seconds', 15)),
        ];
    }

    /** @return array{elapsed_seconds:int,reference:string} */
    private function consumeViewProof(int $userId, int $campaignId): array
    {
        $token = trim((string)($_POST['proof_token'] ?? ''));
        if (preg_match('/^[a-f0-9]{48}$/D', $token) !== 1) {
            throw new \RuntimeException('Dowód obejrzenia wygasł. Otwórz kampanię ponownie.');
        }
        $proofs = $this->app->session->get(self::VIEW_PROOF_SESSION_KEY, []);
        $proofs = is_array($proofs) ? $proofs : [];
        $proof = $proofs[$token] ?? null;
        unset($proofs[$token]);
        $this->app->session->set(self::VIEW_PROOF_SESSION_KEY, $proofs);
        $now = time();
        if (!is_array($proof)
            || (int)($proof['user_id'] ?? 0) !== $userId
            || (int)($proof['campaign_id'] ?? 0) !== $campaignId
            || (int)($proof['expires_at'] ?? 0) < $now
        ) {
            throw new \RuntimeException('Dowód obejrzenia jest nieprawidłowy albo wygasł.');
        }
        $elapsed = max(0, $now - (int)($proof['issued_at'] ?? $now));
        $minimum = max(1, $this->slowoSnajperConfig()->sensitivity('min_ad_watch_seconds', 15));
        $clientVisibleSeconds = max(0, (int)($_POST['visible_seconds'] ?? 0));
        $visible = in_array((string)($_POST['visible'] ?? ''), ['1', 'true', 'visible'], true);
        if ($elapsed < $minimum || $clientVisibleSeconds < $minimum || !$visible) {
            throw new \RuntimeException('Obejrzyj reklamę w widocznym oknie przez wymagany czas.');
        }
        return [
            'elapsed_seconds' => min($elapsed, self::VIEW_PROOF_TTL_SECONDS),
            'reference' => hash('sha256', $token),
        ];
    }

    private function service(): CampaignService
    {
        return new CampaignService(
            $this->app->db,
            $this->talentService(),
            new FraudGuardService($this->app->db, $this->slowoSnajperConfig()),
        );
    }
}
