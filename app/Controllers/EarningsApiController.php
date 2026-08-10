<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ActivityUiHelper;
use App\Services\EarningsNotificationService;

final class EarningsApiController extends BaseController
{
    public function presence(): never
    {
        $userId = $this->app->session->userId();
        if ($userId === null) {
            http_response_code(401);
            $this->json(['ok' => false, 'reason' => 'authentication_required']);
        }

        $rateLimiter = $this->app->rateLimiter;
        $rateKey = 'earnings-presence:' . $userId;
        if ($rateLimiter !== null && $rateLimiter->tooManyAttempts($rateKey, 12)) {
            http_response_code(429);
            header('Retry-After: 60');
            $this->json(['ok' => false, 'reason' => 'rate_limited']);
        }
        $rateLimiter?->hit($rateKey, 60);

        $visible = in_array((string)($_POST['visible'] ?? ''), ['1', 'true', 'visible'], true);
        $result = $this->earningsPresenceService()->ping($userId, $visible);
        $this->json(['ok' => true] + $result);
    }

    public function notifications(): never
    {
        $userId = $this->authenticatedUserId();
        $this->guardRate('earnings-notifications:' . $userId, 60);
        $afterId = max(0, (int)($_GET['after_id'] ?? 0));
        $limit = max(1, min(10, (int)($_GET['limit'] ?? 5)));
        $rows = $this->notificationsService()->pendingAfter($userId, $afterId, $limit);
        $language = function_exists('public_language') ? public_language() : 'pl';
        $items = array_map(static function (array $row) use ($language): array {
            $ui = ActivityUiHelper::resolveRow($row, $language);
            return [
                'id' => (int)$row['id'],
                'activity_type' => (string)$row['activity_type'],
                'points_amount' => (int)$row['points_amount'],
                'amount_minor' => (int)$row['amount_minor'],
                'title' => $ui['title'],
                'message' => ActivityUiHelper::formatRewardMessage(
                    (string)$row['activity_type'],
                    (int)$row['points_amount'],
                    (int)$row['amount_minor'],
                    $language,
                ),
                'icon' => $ui['icon'],
                'created_at' => (string)$row['created_at'],
            ];
        }, $rows);
        $nextCursor = $afterId;
        foreach ($items as $item) {
            $nextCursor = max($nextCursor, (int)$item['id']);
        }
        $this->json([
            'ok' => true,
            'items' => $items,
            'next_cursor' => $nextCursor,
            'unread_count' => $this->notificationsService()->unreadCount($userId),
        ]);
    }

    public function acknowledgeNotifications(): never
    {
        $userId = $this->authenticatedUserId();
        $service = $this->notificationsService();
        $markAll = in_array((string)($_POST['all'] ?? ''), ['1', 'true', 'yes'], true);
        if ($markAll) {
            $acknowledged = $service->acknowledgeAll($userId);
            $this->json([
                'ok' => true,
                'acknowledged' => $acknowledged,
                'unread_count' => $service->unreadCount($userId),
            ]);
        }

        $raw = $_POST['ids'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        $ids = is_array($raw) ? array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn(int $id): bool => $id > 0,
        ))) : [];
        if ($ids === [] || count($ids) > 20) {
            http_response_code(422);
            $this->json(['ok' => false, 'reason' => 'invalid_notification_ids']);
        }
        $acked = $service->acknowledge($userId, $ids);
        $this->json([
            'ok' => true,
            'acknowledged' => $acked,
            'unread_count' => $service->unreadCount($userId),
        ]);
    }

    public function jobStatus(): never
    {
        $userId = $this->authenticatedUserId();
        $this->guardRate('earnings-job-status:' . $userId, 120);
        $publicId = trim((string)($_GET['public_id'] ?? ''));
        if (preg_match('/^[a-f0-9-]{36}$/D', $publicId) !== 1) {
            http_response_code(404);
            $this->json(['ok' => false, 'reason' => 'job_not_found']);
        }
        $job = $this->notificationsService()->jobForUser($publicId, $userId);
        if ($job === null) {
            http_response_code(404);
            $this->json(['ok' => false, 'reason' => 'job_not_found']);
        }
        $result = json_decode((string)($job['result_json'] ?? 'null'), true);
        $this->json([
            'ok' => true,
            'public_id' => $publicId,
            'status' => (string)$job['status'],
            'result' => is_array($result) ? array_intersect_key($result, array_flip([
                'decision', 'reason', 'awarded', 'duplicate', 'points', 'amount_minor', 'notification_id',
            ])) : null,
            'completed_at' => $job['completed_at'] ?? null,
        ]);
    }

    public function articleRead(): never
    {
        $userId = $this->authenticatedUserId();
        $this->guardRate('earnings-article-read:' . $userId, 12);
        $result = $this->articleReadProofService()->complete(
            $userId,
            (int)($_POST['article_id'] ?? 0),
            trim((string)($_POST['proof_token'] ?? '')),
            max(0, (int)($_POST['visible_seconds'] ?? 0)),
            max(0, min(100, (int)($_POST['progress_percent'] ?? 0))),
            in_array((string)($_POST['visible'] ?? ''), ['1', 'true', 'visible'], true),
        );
        if (($result['accepted'] ?? false) !== true) {
            http_response_code(422);
        } else {
            try {
                (new \App\Services\CampaignService(
                    $this->app->db,
                    $this->talentService(),
                    new \App\Services\FraudGuardService($this->app->db, $this->slowoSnajperConfig()),
                ))->recordSponsoredReadForArticle(
                    $userId,
                    (int)($_POST['article_id'] ?? 0),
                    max(0, (int)($_POST['visible_seconds'] ?? 0)),
                    max(0, min(100, (int)($_POST['progress_percent'] ?? 0))),
                    is_string($result['job_public_id'] ?? null) ? $result['job_public_id'] : null,
                );
            } catch (\Throwable $error) {
                error_log('Nie udało się rozliczyć kampanii artykułu: ' . $error->getMessage());
            }
        }
        $this->json(['ok' => ($result['accepted'] ?? false) === true] + $result);
    }

    private function authenticatedUserId(): int
    {
        $userId = $this->app->session->userId();
        if ($userId === null) {
            http_response_code(401);
            $this->json(['ok' => false, 'reason' => 'authentication_required']);
        }
        return $userId;
    }

    private function guardRate(string $key, int $limit): void
    {
        if ($this->app->rateLimiter?->tooManyAttempts($key, $limit)) {
            http_response_code(429);
            header('Retry-After: 60');
            $this->json(['ok' => false, 'reason' => 'rate_limited']);
        }
        $this->app->rateLimiter?->hit($key, 60);
    }

    private function notificationsService(): EarningsNotificationService
    {
        return new EarningsNotificationService($this->app->db);
    }
}
