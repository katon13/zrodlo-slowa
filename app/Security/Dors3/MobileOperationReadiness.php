<?php
declare(strict_types=1);

namespace App\Security\Dors3;

use App\Services\Dors3UiText;

final class MobileOperationReadiness
{
    /** @var array<string,true> */
    private const READY = [
        'auth.login' => true,
        'payout.approve' => true,
        'payout.reject' => true,
        'role.change' => true,
        'article.submit' => true,
        'article.publish' => true,
        'financial_settings.change' => true,
        'safety_fund.disbursement' => true,
        'device.lifecycle' => true,
    ];

    public static function isReady(string $actionType): bool
    {
        return array_key_exists($actionType, self::READY);
    }

    public static function description(string $actionType): string
    {
        return self::isReady($actionType)
            ? Dors3UiText::option('readiness', $actionType)
            : Dors3UiText::get('readiness.not_ready');
    }

    /** @return array<string,array{ready:bool,description:string}> */
    public static function catalog(array $actionTypes): array
    {
        $result = [];
        foreach ($actionTypes as $actionType) {
            $name = (string)$actionType;
            $result[$name] = [
                'ready' => self::isReady($name),
                'description' => self::description($name),
            ];
        }
        return $result;
    }
}
