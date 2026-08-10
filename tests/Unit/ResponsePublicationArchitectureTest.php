<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ResponsePublicationArchitectureTest extends TestCase
{
    public function testMigrationRemovesCommentRuleAndAddsAuditablePublicationSnapshot(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/postgresql/migrations/20260810_009_response_publications.sql');
        foreach (['response_to_article_id','response_reward_qualified','response_reward_points','response_reward_job_public_id'] as $column) {
            self::assertStringContainsString('"' . $column . '"', $sql);
        }
        self::assertStringContainsString("DELETE FROM \"activity_reward_rules\" WHERE \"activity_type\" = 'comment_bonus'", $sql);
        self::assertStringContainsString("'response_publication_bonus'", $sql);
    }

    public function testDepositMigrationStoresAmountStatusAndEveryLedgerReference(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/postgresql/migrations/20260810_010_response_submission_deposit.sql');
        foreach ([
            'submission_deposit_points',
            'response_deposit_points',
            'response_deposit_status',
            'response_deposit_debit_transaction_id',
            'response_deposit_forfeit_transaction_id',
            'response_deposit_reversal_transaction_id',
            'response_deposit_refund_transaction_id',
        ] as $column) {
            self::assertStringContainsString('"' . $column . '"', $sql);
        }
        foreach (['not_required', 'held', 'forfeited', 'refunded'] as $status) {
            self::assertStringContainsString("'{$status}'", $sql);
        }
    }

    public function testPublicArticleAlwaysContainsTheOpinionInsteadOfCommentsPrinciple(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/views/articles/show.php');
        self::assertStringContainsString("t('article.response.kicker'", $view);
        self::assertStringContainsString("t('article.response.title'", $view);
        self::assertStringContainsString('id="opinie-i-polemiki"', $view);
    }

    public function testOpinionDashboardIsNotRewrittenAsAnArticleSlug(): void
    {
        $_GET = [];
        self::assertTrue(seo_reserved_slug('opinie'));
        self::assertSame('/opinie', seo_article_rewrite_uri('/opinie', 'pl'));
    }

    public function testAdministrationExposesRuleSnapshotAndCommentatorControls(): void
    {
        $root = dirname(__DIR__, 2);
        $settings = (string)file_get_contents($root . '/views/admin/settings.php');
        self::assertStringContainsString('$ruleType === \'response_publication_bonus\'', $settings);
        self::assertStringContainsString('Kaucja przy wysłaniu', $settings);
        self::assertStringContainsString('submission_deposit_points', $settings);
        self::assertStringContainsString('Kaucja jest pobierana tylko raz przy pierwszym wysłaniu', $settings);
        self::assertStringContainsString('$isSurveyRule = $ruleType === \'survey_reward\'', $settings);
        self::assertStringContainsString('Ta karta kontroluje wyłącznie TT', $settings);
        self::assertStringContainsString('Wyłączenie tej reguły daje 0 TT, ale nie odbiera należnych PLN', $settings);

        $editorial = (string)file_get_contents($root . '/views/admin/editorial_edit.php');
        self::assertStringContainsString('response_reward_qualified', $editorial);
        self::assertStringContainsString('response_reward_points', $editorial);
        self::assertStringContainsString('response_reward_job_public_id', $editorial);
        self::assertStringContainsString('response_deposit_status', $editorial);
        self::assertStringContainsString('response_deposit_debit_transaction_id', $editorial);

        $users = (string)file_get_contents($root . '/views/admin/users.php');
        self::assertStringContainsString("'commentator' => 'komentator'", $users);
        self::assertStringContainsString('$primaryRole === \'commentator\'', $users);
        self::assertStringContainsString("['can_write','payout_enabled']", $users);
    }

    public function testResponseDashboardAndFormUseCompletePublicTranslations(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = json_decode((string)file_get_contents($root . '/resources/lang/public.json'), true, 512, JSON_THROW_ON_ERROR);
        $usedKeys = [];
        foreach ([
            $root . '/app/Controllers/ResponsePublicationController.php',
            $root . '/views/responses/dashboard.php',
            $root . '/views/responses/form.php',
        ] as $file) {
            preg_match_all('/response\.[a-z0-9_.]+/', (string)file_get_contents($file), $matches);
            $usedKeys = array_merge($usedKeys, $matches[0]);
        }

        foreach (array_values(array_unique($usedKeys)) as $key) {
            self::assertArrayHasKey($key, $catalog, $key);
            foreach (['pl','en','de','fr','it','es'] as $language) {
                self::assertNotSame('', trim((string)($catalog[$key][$language] ?? '')), $key . ':' . $language);
                self::assertSame($catalog[$key][$language], t($key, $language), 'PublicTranslationService:' . $key . ':' . $language);
            }
        }

        $dashboard = (string)file_get_contents($root . '/views/responses/dashboard.php');
        self::assertStringContainsString("t('response.dashboard.empty_title'", $dashboard);
        self::assertStringContainsString("t('response.dashboard.empty_body'", $dashboard);
        self::assertStringNotContainsString('response.dashboard.rule_zero', $dashboard);

        $economy = (string)file_get_contents($root . '/views/economy/show.php');
        self::assertStringNotContainsString('<strong>0 TT</strong>', $economy);
        self::assertStringNotContainsString('nagroda wynosi 0 TT', (string)file_get_contents($root . '/resources/lang/public.json'));

        $form = (string)file_get_contents($root . '/views/responses/form.php');
        self::assertStringContainsString('id="response-image-dropzone"', $form);
        self::assertStringContainsString('id="response-image-preview"', $form);
        self::assertStringContainsString('new DataTransfer()', $form);
        self::assertSame('TT za opublikowaną opinię lub polemikę', $catalog['response.form.talent_notice_title']['pl']);
        self::assertStringNotContainsString('snapshot', mb_strtolower($catalog['response.form.talent_notice_body']['pl'], 'UTF-8'));
        self::assertStringNotContainsString('0 tt', mb_strtolower($catalog['response.form.talent_notice_body']['pl'], 'UTF-8'));
        self::assertSame('Kaucja przy wysłaniu: {points} TT', $catalog['response.form.deposit_notice_title']['pl']);
        self::assertStringContainsString('nie wymaga nowej kaucji', $catalog['response.form.deposit_notice_body']['pl']);
    }

    public function testAuthorResponseUsesExistingArticleSubmitApprovalWhileCommentatorRemainsDirect(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/app/Controllers/ResponsePublicationController.php');
        self::assertStringContainsString("'article.submit'", $controller);
        self::assertStringContainsString('submissionMode($userId, $authorApprovalEnabled)', $controller);
        self::assertStringContainsString('createOperationApprovalRequest', $controller);

        $service = (string)file_get_contents($root . '/app/Services/ResponsePublicationService.php');
        self::assertStringContainsString("? 'dors3_author'", $service);
        self::assertStringContainsString(": 'direct_response'", $service);
    }

    public function testNoRuntimeCodeCanRecordCommentBonus(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['app','views','resources'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php','json'], true)) {
                    continue;
                }
                self::assertStringNotContainsString('comment_bonus', (string)file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }
}
