<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\Dors3\ActionFingerprint;

final class Dors3OperationFingerprintService
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string,mixed> $details
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{fingerprint:string,display_fields:array<string,string>,payload:array<string,mixed>}
     */
    public function adminCritical(
        string $actionType,
        int $adminId,
        string $resourceType,
        string $resourceId,
        array $details,
        array $before,
        array $after,
        int $issuedAt,
    ): array {
        $payload = [
            'action_type' => $actionType,
            'admin_id' => $adminId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'details' => $details,
            'before' => $before,
            'after' => $after,
            'issued_at' => $issuedAt,
        ];
        return [
            'fingerprint' => hash('sha256', ActionFingerprint::canonicalJson($payload)),
            'display_fields' => [
                Dors3UiText::get('fields.operation') => Dors3UiText::option('operations', $actionType),
                Dors3UiText::get('fields.resource') => Dors3UiText::option('resources', $resourceType) . ' #' . $resourceId,
                Dors3UiText::get('fields.before') => mb_substr(ActionFingerprint::canonicalJson($before), 0, 500),
                Dors3UiText::get('fields.after') => mb_substr(ActionFingerprint::canonicalJson($after), 0, 500),
            ],
            'payload' => $payload,
        ];
    }

    /** @return array{fingerprint:string,display_fields:array<string,string>,payload:array<string,mixed>} */
    public function articleSubmit(int $articleId, int $authorId, int $issuedAt): array
    {
        $article = $this->db->one(
            'SELECT * FROM articles WHERE id=:id AND author_id=:author LIMIT 1',
            ['id' => $articleId, 'author' => $authorId]
        );
        if ($article === null || !in_array((string)$article['status'], ['draft', 'rejected'], true)) {
            throw new \RuntimeException('Tekst nie jest gotowy do podpisanego wysłania.');
        }
        $versionId = (int)($this->db->cell(
            'SELECT id FROM article_versions WHERE article_id=:article ORDER BY id DESC LIMIT 1',
            ['article' => $articleId]
        ) ?: 0);
        $media = $this->db->all(
            'SELECT id,path,mime,title,image_position FROM media WHERE article_id=:article ORDER BY id',
            ['article' => $articleId]
        );
        $payload = [
            'article_id' => $articleId,
            'version_id' => $versionId,
            'content_hash' => hash('sha256', implode("\n", [
                (string)$article['title'],
                (string)($article['lead'] ?? ''),
                (string)$article['body'],
            ])),
            'title_hash' => hash('sha256', (string)$article['title']),
            'author_id' => $authorId,
            'organization_id' => 'zrodlo-slowa',
            'visibility' => (string)$article['access_mode'],
            'target_status' => 'submitted',
            'language' => (string)$article['source_language'],
            'attachments_manifest_hash' => hash('sha256', ActionFingerprint::canonicalJson($media)),
            'source_materials_manifest_hash' => $this->emptySourceMaterialsManifestHash(),
            'source_materials_schema' => 'none-v1',
            'issued_at' => $issuedAt,
        ];
        return [
            'fingerprint' => hash('sha256', ActionFingerprint::canonicalJson($payload)),
            'display_fields' => [
                Dors3UiText::get('fields.title') => (string)$article['title'],
                Dors3UiText::get('fields.author') => (string)$authorId,
                Dors3UiText::get('fields.version') => $versionId > 0 ? (string)$versionId : Dors3UiText::get('fields.current_version'),
                Dors3UiText::get('fields.organization') => 'Źródło Słowa',
                Dors3UiText::get('fields.attachments_count') => (string)count($media),
                Dors3UiText::get('fields.source_materials') => Dors3UiText::get('fields.source_materials_none') . ' (none-v1)',
                Dors3UiText::get('fields.result_status') => Dors3UiText::option('statuses', 'submitted'),
                Dors3UiText::get('fields.visibility') => Dors3UiText::option('visibility', (string)$article['access_mode']),
            ],
            'payload' => $payload,
        ];
    }

    /** @return array{fingerprint:string,display_fields:array<string,string>,payload:array<string,mixed>} */
    public function payoutStatus(int $payoutId, string $targetStatus, int $issuedAt): array
    {
        $payout = $this->db->one(
            'SELECT p.*,u.display_name,u.email,pm.account_ref
             FROM payouts p JOIN users u ON u.id=p.user_id
             LEFT JOIN payout_methods pm ON pm.id=p.payout_method_id
             WHERE p.id=:id LIMIT 1',
            ['id' => $payoutId]
        );
        if ($payout === null) {
            throw new \RuntimeException('Wypłata nie istnieje.');
        }
        $account = (string)($payout['account_ref'] ?? '');
        $masked = $account === '' ? Dors3UiText::get('fields.account_missing') : str_repeat('•', max(0, mb_strlen($account) - 4)) . mb_substr($account, -4);
        $amount = (int)$payout['amount_minor'];
        $payload = [
            'payout_id' => $payoutId,
            'recipient_user_id' => (int)$payout['user_id'],
            'masked_account_hash' => hash('sha256', $account),
            'amount_minor' => $amount,
            'currency' => (string)$payout['currency'],
            'fee_minor' => 0,
            'total_minor' => $amount,
            'status_before' => (string)$payout['status'],
            'target_status' => $targetStatus,
            'issued_at' => $issuedAt,
        ];
        return [
            'fingerprint' => hash('sha256', ActionFingerprint::canonicalJson($payload)),
            'display_fields' => [
                Dors3UiText::get('fields.recipient') => (string)$payout['display_name'],
                Dors3UiText::get('fields.account') => $masked,
                Dors3UiText::get('fields.amount') => number_format($amount / 100, 2, ',', ' ') . ' ' . (string)$payout['currency'],
                Dors3UiText::get('fields.fee') => '0,00 ' . (string)$payout['currency'],
                Dors3UiText::get('fields.total') => number_format($amount / 100, 2, ',', ' ') . ' ' . (string)$payout['currency'],
                Dors3UiText::get('fields.before') => Dors3UiText::option('statuses', (string)$payout['status']),
                Dors3UiText::get('fields.after') => Dors3UiText::option('statuses', $targetStatus),
                Dors3UiText::get('fields.operation_id') => (string)$payoutId,
            ],
            'payload' => $payload,
        ];
    }

    /** @return array{fingerprint:string,display_fields:array<string,string>,payload:array<string,mixed>} */
    public function revenueSplitPolicy(
        int $adminId,
        int $authorBasisPoints,
        int $platformBasisPoints,
        int $safetyFundBasisPoints,
        int $issuedAt,
    ): array {
        $service = new SafetyFundService($this->db);
        $service->validatePolicy($authorBasisPoints, $platformBasisPoints, $safetyFundBasisPoints);
        $current = $service->currentPolicy();

        $result = $this->adminCritical(
            'financial_settings.change',
            $adminId,
            'revenue_split_policy',
            'active',
            ['scope' => 'article_purchase_revenue_split'],
            [
                'version' => (int)$current['version'],
                'author_basis_points' => (int)$current['author_basis_points'],
                'platform_basis_points' => (int)$current['platform_basis_points'],
                'safety_fund_basis_points' => (int)$current['safety_fund_basis_points'],
            ],
            [
                'author_basis_points' => $authorBasisPoints,
                'platform_basis_points' => $platformBasisPoints,
                'safety_fund_basis_points' => $safetyFundBasisPoints,
            ],
            $issuedAt,
        );
        $result['display_fields'] = [
            Dors3UiText::get('fields.operation') => Dors3UiText::option('operations', 'financial_settings.change'),
            Dors3UiText::get('fields.author_share') => $this->formatBasisPoints((int)$current['author_basis_points']) . ' → ' . $this->formatBasisPoints($authorBasisPoints),
            Dors3UiText::get('fields.platform_share') => $this->formatBasisPoints((int)$current['platform_basis_points']) . ' → ' . $this->formatBasisPoints($platformBasisPoints),
            Dors3UiText::get('fields.safety_fund_share') => $this->formatBasisPoints((int)$current['safety_fund_basis_points']) . ' → ' . $this->formatBasisPoints($safetyFundBasisPoints),
        ];
        return $result;
    }

    /** @return array{fingerprint:string,display_fields:array<string,string>,payload:array<string,mixed>} */
    public function safetyFundDisbursement(
        int $adminId,
        string $publicId,
        int $amountMinor,
        string $category,
        string $description,
        string $evidenceReference,
        int $issuedAt,
    ): array {
        if ($amountMinor <= 0 || !in_array($category, SafetyFundService::CATEGORIES, true)) {
            throw new \InvalidArgumentException('safety_fund.error.invalid_disbursement');
        }
        $payload = [
            'action_type' => 'safety_fund.disbursement',
            'admin_id' => $adminId,
            'resource_type' => 'safety_fund_disbursement',
            'resource_id' => $publicId,
            'amount_minor' => $amountMinor,
            'currency' => 'PLN',
            'category' => $category,
            'description' => trim($description),
            'evidence_reference' => trim($evidenceReference),
            'issued_at' => $issuedAt,
        ];

        return [
            'fingerprint' => hash('sha256', ActionFingerprint::canonicalJson($payload)),
            'display_fields' => [
                Dors3UiText::get('fields.operation') => Dors3UiText::option('operations', 'safety_fund.disbursement'),
                Dors3UiText::get('fields.amount') => number_format($amountMinor / 100, 2, ',', ' ') . ' PLN',
                Dors3UiText::get('fields.category') => Dors3UiText::option('safety_fund_categories', $category),
                Dors3UiText::get('fields.description') => mb_substr(trim($description), 0, 500),
                Dors3UiText::get('fields.reference') => mb_substr(trim($evidenceReference), 0, 255),
                Dors3UiText::get('fields.operation_id') => $publicId,
            ],
            'payload' => $payload,
        ];
    }

    /** @return array{fingerprint:string,display_fields:array<string,string>,payload:array<string,mixed>} */
    public function articlePublish(int $articleId, int $authorId, int $issuedAt): array
    {
        $article = $this->db->one(
            'SELECT * FROM articles WHERE id=:id AND author_id=:author LIMIT 1',
            ['id' => $articleId, 'author' => $authorId]
        );
        if ($article === null || (string)$article['status'] !== 'approved') {
            throw new \RuntimeException('Tekst nie jest zatwierdzony do publikacji.');
        }
        $versionId = (int)($this->db->cell(
            'SELECT id FROM article_versions WHERE article_id=:article ORDER BY id DESC LIMIT 1',
            ['article' => $articleId]
        ) ?: 0);
        $media = $this->db->all(
            'SELECT id,path,mime,title,image_position FROM media WHERE article_id=:article ORDER BY id',
            ['article' => $articleId]
        );
        $payload = [
            'article_id' => $articleId,
            'version_id' => $versionId,
            'content_hash' => hash('sha256', implode("\n", [(string)$article['title'], (string)($article['lead'] ?? ''), (string)$article['body']])),
            'title_hash' => hash('sha256', (string)$article['title']),
            'author_id' => $authorId,
            'organization_id' => 'zrodlo-slowa',
            'visibility' => (string)$article['access_mode'],
            'target_status' => 'published',
            'language' => (string)$article['source_language'],
            'attachments_manifest_hash' => hash('sha256', ActionFingerprint::canonicalJson($media)),
            'source_materials_manifest_hash' => $this->emptySourceMaterialsManifestHash(),
            'source_materials_schema' => 'none-v1',
            'issued_at' => $issuedAt,
        ];
        return [
            'fingerprint' => hash('sha256', ActionFingerprint::canonicalJson($payload)),
            'display_fields' => [
                Dors3UiText::get('fields.title') => (string)$article['title'],
                Dors3UiText::get('fields.author') => (string)$authorId,
                Dors3UiText::get('fields.version') => $versionId > 0 ? (string)$versionId : Dors3UiText::get('fields.current_version'),
                Dors3UiText::get('fields.organization') => 'Źródło Słowa',
                Dors3UiText::get('fields.attachments_count') => (string)count($media),
                Dors3UiText::get('fields.source_materials') => Dors3UiText::get('fields.source_materials_none') . ' (none-v1)',
                Dors3UiText::get('fields.result_status') => Dors3UiText::option('statuses', 'published'),
                Dors3UiText::get('fields.visibility') => Dors3UiText::option('visibility', (string)$article['access_mode']),
            ],
            'payload' => $payload,
        ];
    }

    private function emptySourceMaterialsManifestHash(): string
    {
        return hash('sha256', ActionFingerprint::canonicalJson([
            'schema' => 'none-v1',
            'items' => [],
        ]));
    }

    private function formatBasisPoints(int $basisPoints): string
    {
        return number_format($basisPoints / 100, 2, ',', ' ') . '%';
    }
}
