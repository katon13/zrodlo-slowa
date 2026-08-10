<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class ResponsePublicationService
{
    public function __construct(private readonly Database $db) {}

    /** @return array{can_respond:bool,role:string,reason:string,payout_enabled:bool} */
    public function eligibility(?int $userId): array
    {
        if ($userId === null || $userId <= 0) {
            return ['can_respond' => false, 'role' => 'guest', 'reason' => 'login_required', 'payout_enabled' => false];
        }
        $user = (new UserService($this->db))->findUserStatus($userId);
        if ($user === null || (string)($user['status'] ?? '') !== 'active') {
            return ['can_respond' => false, 'role' => 'inactive', 'reason' => 'account_inactive', 'payout_enabled' => false];
        }
        $roles = array_values(array_filter(array_map('trim', explode(',', (string)($user['roles'] ?? '')))));
        // An approved author must always keep the author's security path (including
        // 3DORS article.submit when enabled), even when the account also has the
        // commentator role.
        if (in_array(RoleService::ROLE_AUTHOR, $roles, true) && (int)($user['can_write'] ?? 0) === 1) {
            return [
                'can_respond' => true,
                'role' => RoleService::ROLE_AUTHOR,
                'reason' => 'eligible',
                'payout_enabled' => (int)($user['payout_enabled'] ?? 0) === 1,
            ];
        }
        if (in_array(RoleService::ROLE_COMMENTATOR, $roles, true)) {
            return [
                'can_respond' => true,
                'role' => RoleService::ROLE_COMMENTATOR,
                'reason' => 'eligible',
                'payout_enabled' => false,
            ];
        }
        return ['can_respond' => false, 'role' => 'reader', 'reason' => 'role_required', 'payout_enabled' => false];
    }

    public function assertEligible(int $userId): array
    {
        $eligibility = $this->eligibility($userId);
        if (!$eligibility['can_respond']) {
            throw new \RuntimeException('Odpowiedź publikacją może przygotować aktywny komentator albo zatwierdzony autor.');
        }
        return $eligibility;
    }

    public function submissionMode(int $userId, bool $authorApprovalEnabled): string
    {
        $eligibility = $this->assertEligible($userId);
        return $authorApprovalEnabled && $eligibility['role'] === RoleService::ROLE_AUTHOR
            ? 'dors3_author'
            : 'direct_response';
    }

    public function submissionDepositPoints(): int
    {
        $value = $this->db->cell(
            'SELECT submission_deposit_points
             FROM activity_reward_rules
             WHERE activity_type=\'response_publication_bonus\'
             LIMIT 1'
        );
        return max(0, min(1_000_000, (int)($value ?? 0)));
    }

    public function createDraft(int $userId, int $sourceArticleId, array $data): int
    {
        $this->assertEligible($userId);
        return (new ArticleService($this->db))->createResponseDraft($userId, $sourceArticleId, $data);
    }

    public function updateDraft(int $userId, int $articleId, array $data): int
    {
        $this->assertEligible($userId);
        $article = $this->findOwned($articleId, $userId);
        if ($article === null) {
            throw new \RuntimeException('Nie znaleziono opinii lub polemiki.');
        }
        return (new ArticleService($this->db))->updateDraft($articleId, $userId, $data + [
            'access_mode' => 'free',
            'price_minor' => 0,
        ]);
    }

    public function submit(int $userId, int $articleId): void
    {
        $this->assertEligible($userId);
        if ($this->findOwned($articleId, $userId) === null) {
            throw new \RuntimeException('Nie znaleziono opinii lub polemiki.');
        }
        $block = (new UserService($this->db))->authorSubmitBlockInfo($userId);
        if (!empty($block['is_blocked'])) {
            throw new \RuntimeException('Redakcja czasowo zablokowała wysyłanie publikacji z tego konta.');
        }
        (new ArticleService($this->db))->submit($articleId, $userId);
    }

    public function findOwned(int $articleId, int $userId): ?array
    {
        return $this->db->one(
            'SELECT a.*,s.title AS source_title,s.slug AS source_slug
             FROM articles a
             JOIN articles s ON s.id=a.response_to_article_id
             WHERE a.id=:id AND a.author_id=:user AND a.response_to_article_id IS NOT NULL
             LIMIT 1',
            ['id' => $articleId, 'user' => $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->all(
            'SELECT a.*,s.title AS source_title,s.slug AS source_slug
             FROM articles a
             JOIN articles s ON s.id=a.response_to_article_id
             WHERE a.author_id=:user AND a.response_to_article_id IS NOT NULL
             ORDER BY a.updated_at DESC,a.id DESC LIMIT ' . $limit,
            ['user' => $userId]
        );
    }
}
