<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Operator-facing presentation of Talent rules. Activity types remain the
 * persistence contract; every human-facing label comes from the JSON catalog.
 */
final class TalentRulePresenter
{
    private const VISIBLE_TYPES = [
        'registration_bonus', 'day_visit_bonus', 'article_read_bonus', 'response_publication_bonus',
        'bug_report_bonus', 'survey_reward', 'ad_view_reward', 'ad_click_reward',
    ];

    /** @var array<string,array{icon:string}> */
    private const GROUPS = [
        'presence' => ['icon' => 'sun'],
        'community' => ['icon' => 'article'],
        'campaigns' => ['icon' => 'survey'],
    ];

    /** @var array<string,array{group:string,icon:string,badge:bool}> */
    private const RULES = [
        'registration_bonus' => ['group' => 'presence', 'icon' => 'registration', 'badge' => true],
        'day_visit_bonus' => ['group' => 'presence', 'icon' => 'sun', 'badge' => true],
        'article_read_bonus' => ['group' => 'community', 'icon' => 'article', 'badge' => true],
        'response_publication_bonus' => ['group' => 'community', 'icon' => 'article', 'badge' => true],
        'bug_report_bonus' => ['group' => 'community', 'icon' => 'bug', 'badge' => false],
        'survey_reward' => ['group' => 'campaigns', 'icon' => 'survey', 'badge' => true],
        'ad_view_reward' => ['group' => 'campaigns', 'icon' => 'eye', 'badge' => true],
        'ad_click_reward' => ['group' => 'campaigns', 'icon' => 'cursor', 'badge' => true],
    ];

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{key:string,title:string,description:string,icon:string,rules:list<array<string,mixed>>}>
     */
    public static function groups(array $rows, ?string $language = null): array
    {
        $grouped = [];
        foreach (self::GROUPS as $key => $group) {
            $translationPrefix = 'admin.talent.group.' . $key;
            $grouped[$key] = [
                'key' => $key,
                'title' => t($translationPrefix . '.title', $language),
                'description' => t($translationPrefix . '.description', $language),
                'icon' => $group['icon'],
                'rules' => [],
            ];
        }

        foreach ($rows as $row) {
            $type = ActivityUiHelper::normalizeType((string)($row['activity_type'] ?? ''));
            if (!in_array($type, self::VISIBLE_TYPES, true)) {
                continue;
            }

            $definition = self::RULES[$type];
            $group = $definition['group'];
            $translationPrefix = 'admin.talent.rule.' . $type;
            $grouped[$group]['rules'][] = $row + [
                'operator_title' => t($translationPrefix . '.title', $language),
                'operator_description' => t($translationPrefix . '.description', $language),
                'operator_icon' => $definition['icon'],
                'operator_badge' => $definition['badge']
                    ? t($translationPrefix . '.badge', $language)
                    : null,
                'operator_tone' => 'default',
                'operator_readiness' => t('admin.talent.rule.status.working', $language),
                'operator_trigger' => t($translationPrefix . '.trigger', $language),
                'operator_activation_locked' => false,
            ];
        }

        return array_values(array_filter(
            $grouped,
            static fn(array $group): bool => $group['rules'] !== []
        ));
    }
}
