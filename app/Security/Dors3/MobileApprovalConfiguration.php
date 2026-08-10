<?php
declare(strict_types=1);

namespace App\Security\Dors3;

/**
 * Jednoznaczna bramka konfiguracji operacji chronionych przez 3DORS Mobile.
 * W trybie required brak flagi operacji nie może cicho przywracać wykonania
 * bez podpisu telefonu.
 */
final class MobileApprovalConfiguration
{
    /** @param array<string, mixed> $mobile */
    public static function isEnabled(array $mobile, string $variant, string $operationFlag): bool
    {
        if (!self::isVariantEnabled($mobile, $variant)) {
            return false;
        }

        $mode = (string)($mobile['mode'] ?? 'disabled');
        $operationEnabled = (bool)($mobile[$operationFlag] ?? false);
        if ($mode === 'required' && !$operationEnabled) {
            throw new \RuntimeException(
                sprintf('Tryb required 3DORS Mobile nie chroni wymaganej operacji: %s.', $operationFlag),
            );
        }

        return $operationEnabled;
    }

    /** @param array<string, mixed> $mobile */
    public static function isVariantEnabled(array $mobile, string $variant): bool
    {
        MobileOperationPolicy::assertVariant($variant);

        $mode = (string)($mobile['mode'] ?? 'disabled');
        $variantEnabled = (bool)($mobile[$variant . '_app_enabled'] ?? false);
        $mobileEnabled = (bool)($mobile['enabled'] ?? false);

        if ($mode === 'required' && $variantEnabled && !$mobileEnabled) {
            throw new \RuntimeException('Tryb required 3DORS Mobile został globalnie wyłączony.');
        }

        $baseEnabled = $mobileEnabled && in_array($mode, ['test', 'required'], true) && $variantEnabled;

        if (!$baseEnabled) {
            return false;
        }
        return true;
    }
}
