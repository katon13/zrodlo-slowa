<?php
namespace App\Services;

use App\Core\Database;
use App\Core\SlowoSnajperConfig;

final class SlowoSnajperService
{
    public function __construct(
        private readonly Database $db,
        private readonly SlowoSnajperConfig $config,
    ) {}

    public function config(): array
    {
        return $this->config->all();
    }

    public function pageLimitOffset(string $limitKey, mixed $pageRaw, int $fallback = 50, int $hardMax = 500): array
    {
        $limit = $this->config->limit($limitKey, $fallback, $hardMax);
        $page = $this->config->page($pageRaw);
        return [$page, $limit, $this->config->offset($page, $limit)];
    }

    public function audit(?int $userId, string $action, array $payload = []): void
    {
        if (!$this->config->auditEnabled()) {
            return;
        }
        try {
            $subjectUserId = isset($payload['user_id']) && (int)$payload['user_id'] > 0
                ? (int)$payload['user_id']
                : null;
            $result = (string)($payload['result'] ?? 'success');
            (new StructuredAuditService($this->db))->record(
                $userId,
                $action,
                $payload,
                $result,
                $subjectUserId
            );
        } catch (\Throwable) {
            // Audyt jest ważny, ale nie może zatrzymać panelu, jeśli migracja nie jest jeszcze wykonana.
        }
    }
}
