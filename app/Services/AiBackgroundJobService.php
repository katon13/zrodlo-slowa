<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\RequestContext;

final class AiBackgroundJobService
{
    public const QUEUE = 'admin.ai';

    public function __construct(
        private readonly DurableJobQueue $queue,
        private readonly StructuredAuditService $audit,
    ) {}

    /** @return array<string,mixed> */
    public function queueProviderTest(int $actorId, string $permission, string $model): array
    {
        return $this->enqueue(
            'ai.provider_test',
            ['model' => trim($model)],
            'provider-test:' . $actorId . ':' . RequestContext::requestId(),
            $actorId,
            $permission,
        );
    }

    /** @param list<string> $targetLanguages
     *  @return array<string,mixed>
     */
    public function queueArticleTranslation(
        int $actorId,
        string $permission,
        int $articleId,
        array $targetLanguages,
        string $instructions,
        string $sourceHash,
    ): array {
        $payload = [
            'article_id' => $articleId,
            'target_languages' => $targetLanguages,
            'instructions' => $instructions,
            'source_hash' => $sourceHash,
        ];
        return $this->enqueue(
            'ai.article_translation_package',
            $payload,
            'article-translation:' . $articleId . ':' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            $actorId,
            $permission,
        );
    }

    /** @param list<string> $targetLanguages
     *  @return array<string,mixed>
     */
    public function queueMainBannerTranslation(
        int $actorId,
        string $permission,
        array $targetLanguages,
        string $instructions,
        string $sourceHash,
    ): array {
        $payload = [
            'target_languages' => $targetLanguages,
            'instructions' => $instructions,
            'source_hash' => $sourceHash,
        ];
        return $this->enqueue(
            'ai.main_banner_translation',
            $payload,
            'main-banner:' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            $actorId,
            $permission,
        );
    }

    private function enqueue(string $type, array $payload, string $idempotencyKey, int $actorId, string $permission): array
    {
        if ($actorId <= 0 || $permission === '') {
            throw new \InvalidArgumentException('Zadanie AI wymaga aktora i jawnego uprawnienia.');
        }
        $job = $this->queue->enqueue(
            self::QUEUE,
            $type,
            $payload,
            $idempotencyKey,
            priority: -100,
            maxAttempts: 1,
            retryPolicy: 'manual',
            actorUserId: $actorId,
            requiredPermission: $permission,
            requestId: RequestContext::requestId(),
            actorIp: RequestContext::ipAddress(),
        );
        $this->audit->record($actorId, 'ai.background_job.enqueued', [
            'background_job_id' => (int)$job['id'],
            'public_id' => (string)$job['public_id'],
            'job_type' => $type,
            'required_permission' => $permission,
            'idempotency_key' => $idempotencyKey,
            'duplicate' => (bool)$job['duplicate'],
            'request_id' => RequestContext::requestId(),
        ]);
        return $job;
    }
}
