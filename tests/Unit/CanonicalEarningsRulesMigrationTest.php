<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CanonicalEarningsRulesMigrationTest extends TestCase
{
    private const TYPES = [
        'registration_bonus',
        'day_visit_bonus',
        'login_bonus',
        'article_read_bonus',
        'comment_bonus',
        'share_bonus',
        'link_click_bonus',
        'like_bonus',
        'bug_report_bonus',
        'survey_reward',
        'sponsored_article_read_bonus',
        'ad_view_reward',
        'ad_click_reward',
        'newsletter_open_reward',
        'ppv_reward',
        'live_event_reward',
    ];

    public function testCanonicalRulesAreSeededDisabledAndWithoutValue(): void
    {
        $path = dirname(__DIR__, 2)
            . '/database/postgresql/migrations/20260801_005_canonical_earnings_rules.sql';
        $sql = (string) file_get_contents($path);

        self::assertStringContainsString('ON CONFLICT ("activity_type") DO NOTHING', $sql);

        foreach (self::TYPES as $type) {
            self::assertMatchesRegularExpression(
                "/\\('" . preg_quote($type, '/') . "', 0, 0, NULL, NULL, '[^']+', '[^']+', '[^']+', 0, 0, NOW\\(\\), NOW\\(\\)\\)/",
                $sql,
                $type
            );
        }

        self::assertSame(count(self::TYPES), substr_count($sql, "NOW(), NOW())"));
    }
}
