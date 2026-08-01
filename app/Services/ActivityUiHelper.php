<?php
namespace App\Services;

/**
 * ActivityUiHelper - centralne mapowanie zdarzeń/operacji na klucze JSON UI.
 *
 * Zasada:
 * - baza trzyma typ zdarzenia, wartości, daty i referencje,
 * - JSON trzyma nazwy, opisy i komunikaty,
 * - ten helper jest jedyną warstwą łączącą typ z bazy z tekstem UI.
 */
final class ActivityUiHelper
{
    /** @var array<string,array{suffix:string,icon:string}> */
    private const EVENT_MAP = [
        'activity_bonus' => ['suffix' => 'activity_bonus', 'icon' => 'points'],
        'registration_bonus' => ['suffix' => 'registration', 'icon' => 'registration'],
        'login_bonus' => ['suffix' => 'login', 'icon' => 'login'],
        'day_visit_bonus' => ['suffix' => 'day_visit', 'icon' => 'sun'],
        'article_read_bonus' => ['suffix' => 'article_read', 'icon' => 'article'],
        'comment_bonus' => ['suffix' => 'comment', 'icon' => 'comment'],
        'poll_bonus' => ['suffix' => 'poll_answer', 'icon' => 'survey'],
        'poll_answer_bonus' => ['suffix' => 'poll_answer', 'icon' => 'survey'],
        'survey_reward' => ['suffix' => 'survey_reward', 'icon' => 'survey'],
        'link_click_bonus' => ['suffix' => 'link_click', 'icon' => 'cursor'],
        'like_bonus' => ['suffix' => 'like', 'icon' => 'like'],
        'share_bonus' => ['suffix' => 'share', 'icon' => 'share'],
        'bug_report_bonus' => ['suffix' => 'bug_report', 'icon' => 'bug'],
        'sponsored_article_read_bonus' => ['suffix' => 'sponsored_article_read', 'icon' => 'article'],
        'ad_watch_bonus' => ['suffix' => 'ad_watch', 'icon' => 'eye'],
        'ad_view_reward' => ['suffix' => 'ad_view_reward', 'icon' => 'eye'],
        'ad_read_bonus' => ['suffix' => 'ad_read', 'icon' => 'article'],
        'ad_click_reward' => ['suffix' => 'ad_click_reward', 'icon' => 'cursor'],
        'newsletter_open_reward' => ['suffix' => 'newsletter_open_reward', 'icon' => 'mail'],
        'ppv_reward' => ['suffix' => 'ppv_reward', 'icon' => 'video'],
        'live_event_reward' => ['suffix' => 'live_event_reward', 'icon' => 'video'],
        'manual_reward' => ['suffix' => 'manual_reward', 'icon' => 'star'],
        'article_sale_income' => ['suffix' => 'article_sale_income', 'icon' => 'article'],
        'article_support_income' => ['suffix' => 'article_support_income', 'icon' => 'wallet'],

        'wallet_topup' => ['suffix' => 'wallet_topup', 'icon' => 'wallet'],
        'article_payment' => ['suffix' => 'article_payment', 'icon' => 'article'],
        'payout' => ['suffix' => 'payout', 'icon' => 'payout'],
        'payout_request' => ['suffix' => 'payout_request', 'icon' => 'payout'],
        'payout_approved' => ['suffix' => 'payout_approved', 'icon' => 'payout'],
        'payout_paid' => ['suffix' => 'payout_paid', 'icon' => 'bank'],
        'payout_rejected' => ['suffix' => 'payout_rejected', 'icon' => 'warning'],
        'adjustment' => ['suffix' => 'adjustment', 'icon' => 'finance'],
        'transfer_in' => ['suffix' => 'transfer_in', 'icon' => 'wallet'],
        'transfer_out' => ['suffix' => 'transfer_out', 'icon' => 'wallet'],
        'platform_fee' => ['suffix' => 'platform_fee', 'icon' => 'finance'],
    ];

    public static function isMapped(string $type): bool
    {
        return isset(self::EVENT_MAP[self::normalizeType($type)]);
    }

    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return match ($type) {
            'daily_visit_bonus' => 'day_visit_bonus',
            'article_read' => 'article_read_bonus',
            'login' => 'login_bonus',
            'registration' => 'registration_bonus',
            'poll_answer' => 'poll_answer_bonus',
            'ad_watch' => 'ad_watch_bonus',
            default => $type,
        };
    }

    public static function suffixFor(string $type): string
    {
        $type = self::normalizeType($type);
        if (isset(self::EVENT_MAP[$type])) {
            return self::EVENT_MAP[$type]['suffix'];
        }
        return preg_replace('/_bonus$/', '', $type) ?: $type;
    }

    public static function titleKeyFor(string $type): string
    {
        return 'bonus.type.' . self::suffixFor($type);
    }

    public static function messageKeyFor(string $type): string
    {
        return 'bonus.message.' . self::suffixFor($type);
    }

    public static function descriptionKeyFor(string $type): string
    {
        return 'bonus.description.' . self::suffixFor($type);
    }

    /** @return array{title_key:string,message_key:string,description_key:string,icon:string} */
    public static function keysFor(string $type): array
    {
        $normalized = self::normalizeType($type);
        return [
            'title_key' => self::titleKeyFor($normalized),
            'message_key' => self::messageKeyFor($normalized),
            'description_key' => self::descriptionKeyFor($normalized),
            'icon' => self::getIconName($normalized),
        ];
    }

    public static function getIconName(string $type): string
    {
        $normalized = self::normalizeType($type);
        if (isset(self::EVENT_MAP[$normalized])) {
            return self::EVENT_MAP[$normalized]['icon'];
        }
        return function_exists('zs_type_icon') ? zs_type_icon($normalized) : 'points';
    }

    public static function renderIcon(string $type, string $class = ''): string
    {
        return function_exists('zs_icon') ? zs_icon(self::getIconName($type), $class) : '';
    }

    private static function translated(string $key, ?string $lang, ?string $fallback = null): string
    {
        $value = function_exists('t') ? t($key, $lang) : '';
        if (trim($value) !== '' && $value !== $key) {
            return $value;
        }
        return $fallback ?? ucfirst(str_replace(['_', '-'], ' ', basename(str_replace('.', '/', $key))));
    }

    public static function getLabel(string $type, ?string $lang = null): string
    {
        $type = self::normalizeType($type);
        $translated = self::translated(self::titleKeyFor($type), $lang, '');
        if ($translated !== '') {
            return $translated;
        }
        if (class_exists(LedgerService::class)) {
            $map = LedgerService::typeMap();
            return function_exists('zs_clean_description') ? zs_clean_description($map[$type] ?? $type) : ($map[$type] ?? $type);
        }
        return $type;
    }

    public static function getMessage(string $type, ?string $lang = null): string
    {
        $type = self::normalizeType($type);
        $message = self::translated(self::messageKeyFor($type), $lang, '');
        return $message !== '' ? $message : self::getLabel($type, $lang);
    }

    public static function getDescription(string $type, ?string $rawDescription = null, ?string $lang = null): string
    {
        $type = self::normalizeType($type);
        if (self::isMapped($type)) {
            $description = self::translated(self::descriptionKeyFor($type), $lang, '');
            if ($description !== '') {
                return $description;
            }
            $message = self::getMessage($type, $lang);
            if ($message !== '') {
                return $message;
            }
        }

        if ($rawDescription !== null && trim($rawDescription) !== '') {
            return function_exists('zs_clean_description') ? zs_clean_description($rawDescription) : $rawDescription;
        }

        return self::getLabel($type, $lang);
    }


    /**
     * Rozwiązanie UI z rekordu bazy.
     * Najpierw honoruje *_key zapisane w tabeli, dopiero potem używa mapowania typu.
     * Dzięki temu tabela może trzymać bezpośrednie odniesienia do JSON, a nie tekst UI.
     *
     * @param array<string,mixed> $row
     * @return array{title:string,message:string,description:string,icon:string,title_key:string,message_key:string,description_key:string,type:string}
     */
    public static function resolveRow(array $row, ?string $lang = null): array
    {
        $type = (string)($row['activity_type'] ?? $row['type'] ?? 'activity_bonus');
        $type = self::normalizeType($type);

        $generated = self::keysFor($type);
        $titleKey = self::cleanKey($row['title_key'] ?? null) ?: $generated['title_key'];
        $messageKey = self::cleanKey($row['message_key'] ?? null) ?: $generated['message_key'];
        $descriptionKey = self::cleanKey($row['description_key'] ?? null) ?: $generated['description_key'];

        $rawDescription = $row['description'] ?? $row['live_message'] ?? $row['message'] ?? null;
        $rawDescription = is_string($rawDescription) ? trim($rawDescription) : null;

        $title = self::translated($titleKey, $lang, '');
        if ($title === '') {
            $title = self::getLabel($type, $lang);
        }

        $message = self::translated($messageKey, $lang, '');
        if ($message === '') {
            $message = $title;
        }

        $description = self::translated($descriptionKey, $lang, '');
        if ($description === '') {
            if (self::isMapped($type)) {
                $description = $message;
            } elseif ($rawDescription !== null && $rawDescription !== '' && !str_starts_with($rawDescription, 'bonus.')) {
                $description = function_exists('zs_clean_description') ? zs_clean_description($rawDescription) : $rawDescription;
            } else {
                $description = $message;
            }
        }

        return [
            'title' => $title,
            'message' => $message,
            'description' => $description,
            'icon' => $generated['icon'],
            'title_key' => $titleKey,
            'message_key' => $messageKey,
            'description_key' => $descriptionKey,
            'type' => $type,
        ];
    }

    private static function cleanKey(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || !str_contains($value, '.')) {
            return null;
        }
        return $value;
    }

    /**
     * Komunikat live z JSON; baza nie jest źródłem tekstu, tylko typu i wartości.
     */
    public static function formatRewardMessage(string $type, int $points, int $amountMinor, ?string $lang = null): string
    {
        $msg = self::getMessage($type, $lang);

        if ($points > 0) {
            return $msg . ' (+' . $points . ' TT)';
        }

        if ($amountMinor > 0) {
            $cache = isset($GLOBALS['app']) && $GLOBALS['app'] instanceof \App\Core\App
                ? $GLOBALS['app']->cache
                : null;
            $currencyService = new CurrencyRateService($cache);
            $formatted = $currencyService->formatSimple($amountMinor / 100, 'PLN', $lang ?? (function_exists('public_language') ? public_language() : 'pl'));
            return $msg . ' (+' . $formatted . ')';
        }

        return $msg;
    }

    /** @return array{title:string,message:string,description:string,icon:string,title_key:string,message_key:string,description_key:string} */
    public static function resolve(string $type, ?string $lang = null, ?string $rawDescription = null): array
    {
        $keys = self::keysFor($type);
        return [
            'title' => self::getLabel($type, $lang),
            'message' => self::getMessage($type, $lang),
            'description' => self::getDescription($type, $rawDescription, $lang),
            'icon' => $keys['icon'],
            'title_key' => $keys['title_key'],
            'message_key' => $keys['message_key'],
            'description_key' => $keys['description_key'],
        ];
    }
}
