<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ValkeyClientInterface;
use App\Core\SlowoSnajperConfig;
use App\Jobs\NotificationOutboxJobHandler;

final class EarningsPresenceService
{
    private BusinessClock $businessClock;

    public function __construct(
        private readonly ?ValkeyClientInterface $valkey,
        private readonly SlowoSnajperConfig $config,
        private readonly EarningsJobDispatcher $dispatcher,
        ?BusinessClock $businessClock = null,
    ) {
        $this->businessClock = $businessClock ?? BusinessClock::fromEnvironment();
    }

    /** @return array<string,mixed> */
    public function ping(int $userId, bool $visible): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Nieprawidłowy użytkownik pingu obecności.');
        }
        if (!$this->config->earningsWorkerEnabled() || !$this->config->earningsPresenceEnabled()) {
            return ['present' => false, 'queued' => false, 'reason' => 'presence_disabled'];
        }
        if ($this->config->earningsPresenceVisibleOnly() && !$visible) {
            $this->clear($userId);
            return ['present' => false, 'queued' => false, 'reason' => 'tab_hidden'];
        }

        $observedAt = gmdate('c');
        $ttl = $this->config->earningsPresenceTtlSeconds();
        $presenceStored = false;
        $firstInInterval = true;
        if ($this->valkey !== null) {
            try {
                $presenceStored = $this->valkey->set(
                    $this->presenceKey($userId),
                    json_encode([
                        'user_id' => $userId,
                        'observed_at' => $observedAt,
                        'visibility_state' => 'visible',
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $ttl,
                );
                $firstInInterval = $this->valkey->setIfAbsent(
                    $this->intervalKey($userId),
                    $observedAt,
                    172800000,
                );
            } catch (\Throwable $error) {
                error_log('Valkey presence niedostępne; deduplikacja PostgreSQL pozostaje aktywna: ' . $error->getMessage());
                $firstInInterval = true;
            }
        }

        $job = null;
        if ($firstInInterval) {
            try {
                $job = $this->dispatcher->queueTalentAward(
                    $userId,
                    'day_visit_bonus',
                    context: [
                        'observed_at' => $observedAt,
                        'presence_verified' => true,
                        'interval_key' => $this->businessClock->dayKey(),
                        'visibility_state' => 'visible',
                    ],
                );
            } catch (\Throwable $error) {
                // Valkey jest tylko szybką deduplikacją. Gdy trwały enqueue się nie uda,
                // zwalniamy wyłącznie klucz zdobyty przez ten ping, aby następny mógł ponowić.
                if ($this->valkey !== null) {
                    try {
                        $this->valkey->delete($this->intervalKey($userId));
                    } catch (\Throwable) {
                        // PostgreSQL pozostaje ostatecznym zabezpieczeniem idempotencji.
                    }
                }
                throw $error;
            }
        }

        return [
            'present' => true,
            'presence_stored' => $presenceStored,
            'ttl_seconds' => $ttl,
            'ping_seconds' => $this->config->earningsPresencePingSeconds(),
            'first_in_interval' => $firstInInterval,
            'queued' => is_array($job) && ($job['queued'] ?? false) === true,
            'job_public_id' => is_array($job) ? ($job['public_id'] ?? null) : null,
            'notification_hint_id' => $this->notificationHintId($userId),
        ];
    }

    public function clear(int $userId): void
    {
        if ($userId <= 0 || $this->valkey === null) {
            return;
        }
        try {
            $this->valkey->delete($this->presenceKey($userId));
        } catch (\Throwable) {
            // Krótki TTL sam usunie osieroconą dzierżawę.
        }
    }

    /** @return array<string,mixed>|null */
    public function current(int $userId): ?array
    {
        if ($userId <= 0 || $this->valkey === null) {
            return null;
        }
        try {
            $json = $this->valkey->get($this->presenceKey($userId));
            $value = $json !== null ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
            return is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function presenceKey(int $userId): string
    {
        return 'earnings-presence:user:' . $userId;
    }

    private function intervalKey(int $userId): string
    {
        return 'earnings-presence-award:day-visit:' . $this->businessClock->dayKey() . ':user:' . $userId;
    }

    private function notificationHintId(int $userId): int
    {
        if ($this->valkey === null) {
            return 0;
        }
        try {
            return max(0, (int)($this->valkey->get(NotificationOutboxJobHandler::hintKey($userId)) ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }
}
