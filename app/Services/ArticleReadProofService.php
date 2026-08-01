<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ValkeyClientInterface;
use App\Core\SlowoSnajperConfig;

final class ArticleReadProofService
{
    private \Closure $now;

    public function __construct(
        private readonly ?ValkeyClientInterface $valkey,
        private readonly SlowoSnajperConfig $config,
        private readonly EarningsJobDispatcher $dispatcher,
        ?callable $now = null,
    ) {
        $this->now = $now !== null ? \Closure::fromCallable($now) : static fn(): int => time();
    }

    /** @return array<string,mixed>|null */
    public function start(int $userId, int $articleId): ?array
    {
        if (!$this->config->articleReadProofEnabled() || $this->valkey === null || $userId <= 0 || $articleId <= 0) {
            return null;
        }
        $token = bin2hex(random_bytes(24));
        $payload = [
            'user_id' => $userId,
            'article_id' => $articleId,
            'issued_at' => ($this->now)(),
        ];
        try {
            $stored = $this->valkey->set(
                $this->key($token),
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $this->config->articleReadProofTtlSeconds(),
            );
        } catch (\Throwable) {
            return null;
        }
        if (!$stored) {
            return null;
        }
        return [
            'token' => $token,
            'article_id' => $articleId,
            'min_visible_seconds' => $this->config->articleReadMinimumVisibleSeconds(),
            'min_progress_percent' => $this->config->articleReadMinimumProgressPercent(),
        ];
    }

    /** @return array<string,mixed> */
    public function complete(
        int $userId,
        int $articleId,
        string $token,
        int $visibleSeconds,
        int $progressPercent,
        bool $visible,
    ): array {
        if ($this->valkey === null || preg_match('/^[a-f0-9]{48}$/D', $token) !== 1) {
            return ['accepted' => false, 'reason' => 'invalid_proof'];
        }
        try {
            $stored = $this->valkey->get($this->key($token));
            $proof = $stored !== null ? json_decode($stored, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            return ['accepted' => false, 'reason' => 'proof_unavailable'];
        }
        if (
            !is_array($proof)
            || (int)($proof['user_id'] ?? 0) !== $userId
            || (int)($proof['article_id'] ?? 0) !== $articleId
        ) {
            return ['accepted' => false, 'reason' => 'invalid_proof'];
        }
        $elapsed = ($this->now)() - (int)($proof['issued_at'] ?? 0);
        if ($elapsed < $this->config->articleReadMinimumVisibleSeconds()) {
            return ['accepted' => false, 'reason' => 'minimum_time'];
        }
        if ($elapsed > $this->config->articleReadProofTtlSeconds()) {
            return ['accepted' => false, 'reason' => 'expired_proof'];
        }
        if ($visibleSeconds < $this->config->articleReadMinimumVisibleSeconds()) {
            return ['accepted' => false, 'reason' => 'minimum_visible_time'];
        }
        if (!$visible) {
            return ['accepted' => false, 'reason' => 'tab_hidden'];
        }
        if ($progressPercent < $this->config->articleReadMinimumProgressPercent()) {
            return ['accepted' => false, 'reason' => 'minimum_progress'];
        }

        $job = $this->dispatcher->queueTalentAward(
            $userId,
            'article_read_bonus',
            'article',
            $articleId,
            [
                'proof_verified' => true,
                'proof_type' => 'article_read',
                'visible_seconds' => $visibleSeconds,
                'progress_percent' => min(100, $progressPercent),
            ],
        );
        try {
            $this->valkey->compareAndDelete($this->key($token), (string)$stored);
        } catch (\Throwable) {
            // Stabilny klucz PostgreSQL nadal gwarantuje jedno zadanie dla artykułu.
        }
        return [
            'accepted' => true,
            'queued' => ($job['queued'] ?? false) === true,
            'job_public_id' => $job['public_id'] ?? null,
        ];
    }

    private function key(string $token): string
    {
        return 'article-read-proof:' . $token;
    }
}
