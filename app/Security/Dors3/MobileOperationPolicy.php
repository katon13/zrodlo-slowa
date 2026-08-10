<?php
declare(strict_types=1);

namespace App\Security\Dors3;

final class MobileOperationPolicy
{
    /** @var array<string,list<string>> */
    private const OPERATIONS = [
        'admin' => [
            'payout.approve', 'payout.reject', 'wallet.adjust', 'payout_details.admin_change',
            'ledger.manual_correction', 'role.change', 'security.settings.change',
            'device.register', 'device.revoke', 'admin.approve', 'dors3.policy.change',
            'backup.restore', 'sensitive_data.export', 'financial_settings.change',
            'safety_fund.disbursement', 'payout_details.change', 'wallet.own_operation',
        ],
        'author' => [
            'article.submit', 'article.send_to_editor', 'article.approve_version',
            'article.publish', 'article.unpublish', 'article.export_sources',
            'confidential_material.access', 'agreement.action',
        ],
    ];

    public static function assertVariant(string $variant): void
    {
        if (!array_key_exists($variant, self::OPERATIONS)) {
            throw new \InvalidArgumentException('Nieobsługiwany wariant aplikacji 3DORS.');
        }
    }

    public static function allows(string $variant, string $actionType): bool
    {
        return isset(self::OPERATIONS[$variant]) && in_array($actionType, self::OPERATIONS[$variant], true);
    }

    public static function assertAllowed(string $variant, string $actionType): void
    {
        self::assertVariant($variant);
        if (!self::allows($variant, $actionType)) {
            throw new \DomainException('Wariant aplikacji nie może zatwierdzić tej operacji.');
        }
    }

    public static function requiredVariant(string $actionType): string
    {
        foreach (self::OPERATIONS as $variant => $operations) {
            if (in_array($actionType, $operations, true)) {
                return $variant;
            }
        }
        throw new \DomainException('Typ operacji nie ma przypisanego wariantu aplikacji 3DORS.');
    }

    public static function debugLaunchUri(string $variant, string $requestPublicId): string
    {
        self::assertVariant($variant);
        $scheme = $variant === 'admin' ? 'dors3-admin-dev' : 'dors3-author-dev';
        return $scheme . '://approve/' . rawurlencode($requestPublicId);
    }
}
