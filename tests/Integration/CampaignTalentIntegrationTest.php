<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\EarningsJobHandler;
use App\Services\AuthService;
use App\Services\BugReportService;
use App\Services\CampaignService;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;
use App\Services\EarningsQueueService;
use App\Services\FinancialService;
use App\Services\LedgerService;
use App\Services\SurveyService;
use App\Services\TalentService;

final class CampaignTalentIntegrationTest extends DatabaseTestCase
{
    public function testVideoRequiresCampaignWatchTimeAndKeepsTalentSnapshot(): void
    {
        $this->setRule('ad_view_reward', 20, true);
        $campaignId = $this->campaigns()->create($this->adminId(), [
            'client_name' => 'Reklamodawca filmu',
            'name' => 'Film PHPUnit',
            'type' => 'ad_view',
            'status' => 'active',
            'creative_path' => '/uploads/campaigns/phpunit-film.mp4',
            'creative_mime' => 'video/mp4',
            'budget' => '30,00',
            'cost_per_view' => '3,00',
            'minimum_view_seconds' => 30,
            'budget_confirmed' => '1',
        ]);
        try {
            $this->campaigns()->recordView($this->ordinaryUserId(), $campaignId, 29, 'too-short');
            self::fail('Zbyt krótkie obejrzenie nie może zużyć budżetu.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('wymagany czas', $error->getMessage());
        }
        self::assertSame(0, (int)$this->database->cell(
            'SELECT COUNT(*) FROM campaign_events WHERE campaign_id=:id',
            ['id' => $campaignId],
        ));

        $result = $this->campaigns()->recordView($this->ordinaryUserId(), $campaignId, 30, 'valid-watch');
        self::assertNotNull($result);
        $eventId = (int)$result['event_id'];
        self::assertSame(20, (int)$this->database->cell(
            'SELECT talent_points_snapshot FROM campaign_events WHERE id=:id',
            ['id' => $eventId],
        ));
        self::assertSame(0, (int)$this->campaigns()->report($campaignId)['summary']['rewarded_points']);

        $this->setRule('ad_view_reward', 1, false);
        self::assertSame(1, $this->worker()->runOne()['completed']);
        $report = $this->campaigns()->report($campaignId);
        self::assertSame(20, (int)$report['summary']['rewarded_points']);
        self::assertSame(300, (int)$report['summary']['spent_minor']);
    }

    public function testSponsoredArticleBillsOneVerifiedReadAndReusesArticleTalentJob(): void
    {
        $this->setRule('article_read_bonus', 15, true);
        $userId = $this->ordinaryUserId();
        $articleId = $this->publishedArticle($userId, 'Sponsorowany tekst PHPUnit');
        $campaignId = $this->campaigns()->create($this->adminId(), [
            'client_name' => 'Partner artykułu',
            'name' => 'Artykuł sponsorowany PHPUnit',
            'type' => 'sponsored_article',
            'status' => 'active',
            'linked_article_id' => $articleId,
            'budget' => '20,00',
            'cost_per_view' => '2,00',
            'budget_confirmed' => '1',
        ]);
        self::assertSame('Sponsored', (string)$this->database->cell(
            'SELECT article_label FROM articles WHERE id=:id',
            ['id' => $articleId],
        ));
        $job = $this->talent()->queueAward($userId, 'article_read_bonus', 'article', $articleId, [
            'proof_verified' => true,
            'proof_type' => 'article_read',
            'visible_seconds' => 45,
            'progress_percent' => 90,
        ]);
        self::assertTrue((bool)$job['queued']);

        $recorded = $this->campaigns()->recordSponsoredReadForArticle(
            $userId,
            $articleId,
            45,
            90,
            (string)$job['public_id'],
        );
        self::assertCount(1, $recorded);
        $event = $this->database->one(
            'SELECT * FROM campaign_events WHERE id=:id',
            ['id' => (int)$recorded[0]['event_id']],
        );
        self::assertSame((string)$job['public_id'], (string)$event['talent_job_public_id']);
        self::assertSame(200, (int)$event['cost_minor']);
        self::assertSame([], $this->campaigns()->recordSponsoredReadForArticle(
            $userId,
            $articleId,
            45,
            90,
            (string)$job['public_id'],
        ));
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM background_jobs WHERE public_id=:job AND job_type='earnings.talent_award'",
            ['job' => (string)$job['public_id']],
        ));

        self::assertSame(1, $this->worker()->runOne()['completed']);
        self::assertSame(15, (int)$this->campaigns()->report($campaignId)['summary']['rewarded_points']);
    }

    public function testSurveyCampaignUsesTheExistingSurveyJobWithoutASecondReward(): void
    {
        $this->setRule('survey_reward', 10, true);
        $survey = new SurveyService($this->database);
        $surveyId = $survey->createSurvey($this->adminId(), [
            'title' => 'Ankieta kampanii PHPUnit',
            'status' => 'draft',
            'budget' => '0',
            'reward_amount' => '0',
            'max_responses' => 10,
        ]);
        $questionId = $survey->addQuestion($surveyId, [
            'question_text' => 'Czy kampania jest czytelna?',
            'question_type' => 'single_choice',
            'options' => "Tak\nNie",
            'is_required' => '1',
        ]);
        $survey->updateSurvey($surveyId, [
            'title' => 'Ankieta kampanii PHPUnit',
            'status' => 'active',
            'budget' => '0',
            'reward_amount' => '0',
            'max_responses' => 10,
        ]);
        $campaignId = $this->campaigns()->create($this->adminId(), [
            'client_name' => 'Partner ankiety',
            'name' => 'Kampania ankietowa PHPUnit',
            'type' => 'survey_ad',
            'status' => 'active',
            'linked_survey_id' => $surveyId,
            'budget' => '20,00',
            'cost_per_completed_survey' => '2,00',
            'budget_confirmed' => '1',
        ]);

        $_POST['answer_seconds'] = 30;
        $responseId = $survey->submitResponse($surveyId, $this->ordinaryUserId(), [$questionId => 'Tak']);
        $recorded = $this->campaigns()->recordSurveyCompletion($this->ordinaryUserId(), $surveyId, $responseId);
        self::assertCount(1, $recorded);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM background_jobs WHERE idempotency_key=:key',
            ['key' => 'survey-response:' . $responseId],
        ));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM campaign_events WHERE campaign_id=:campaign AND event_type=\'survey_completed\'',
            ['campaign' => $campaignId],
        ));
        self::assertSame(1, $this->worker()->runOne()['completed']);
        self::assertSame(10, (int)$this->campaigns()->report($campaignId)['summary']['rewarded_points']);
    }

    public function testAcceptedBugSnapshotsOneRewardWhileRejectedReportAwardsNothing(): void
    {
        $this->setRule('bug_report_bonus', 77, true);
        $service = new BugReportService($this->database, $this->talent());
        $userId = $this->ordinaryUserId();
        $rejectedId = $service->create(
            $userId,
            'http://localhost:8080/pl/articles',
            'Błąd odrzucony',
            'Otwórz listę artykułów i odśwież stronę.',
            '/uploads/bug-reports/rejected.png',
            'image/png',
        );
        $service->reject($rejectedId, $this->adminId(), 'To nie jest błąd systemu.');
        self::assertSame(0, (int)$this->database->cell(
            "SELECT COUNT(*) FROM background_jobs WHERE job_type='earnings.talent_award' AND payload_json->>'activity_type'='bug_report_bonus'",
        ));

        $acceptedId = $service->create(
            $userId,
            'http://localhost:8080/pl/wallet',
            'Prawdziwy błąd',
            'Kroki odtworzenia',
            '/uploads/bug-reports/accepted.webp',
            'image/webp',
        );
        $accepted = $service->accept($acceptedId, $this->adminId(), 'Potwierdzone przez redakcję.');
        self::assertFalse((bool)$accepted['duplicate']);
        self::assertSame(77, (int)$accepted['points']);
        $again = $service->accept($acceptedId, $this->adminId(), 'Powtórzenie decyzji');
        self::assertTrue((bool)$again['duplicate']);
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM background_jobs WHERE job_type='earnings.talent_award' AND payload_json->>'activity_type'='bug_report_bonus'",
        ));

        $this->setRule('bug_report_bonus', 1, false);
        self::assertSame(1, $this->worker()->runOne()['completed']);
        self::assertSame(77, (int)$this->database->cell(
            "SELECT points_amount FROM activity_reward_logs WHERE activity_type='bug_report_bonus' AND reference_type='bug_report' AND reference_id=:id",
            ['id' => $acceptedId],
        ));
    }

    public function testBugReportRequiresReproductionStepsAndScreenshot(): void
    {
        $service = new BugReportService($this->database, $this->talent());
        $this->expectException(\InvalidArgumentException::class);
        $service->create(
            $this->ordinaryUserId(),
            'http://localhost:8080/pl/articles',
            'Opis błędu',
            'Kroki odtworzenia',
            null,
            null,
        );
    }

    private function campaigns(): CampaignService
    {
        return new CampaignService($this->database, $this->talent());
    }

    private function talent(): TalentService
    {
        return new TalentService(
            $this->database,
            new LedgerService($this->database, new FinancialService($this->database)),
        );
    }

    private function worker(): DurableJobWorker
    {
        return new DurableJobWorker(
            new DurableJobQueue($this->database),
            new EarningsJobHandler($this->database),
            EarningsQueueService::QUEUE,
            'phpunit-campaign-talent-worker',
        );
    }

    private function setRule(string $type, int $points, bool $active): void
    {
        $this->database->query(
            'UPDATE activity_reward_rules SET points_amount=:points,amount_minor=0,daily_limit=0,is_active=:active WHERE activity_type=:type',
            ['points' => $points, 'active' => $active ? 1 : 0, 'type' => $type],
        );
    }

    private function publishedArticle(int $authorId, string $title): int
    {
        $slug = 'phpunit-sponsored-' . bin2hex(random_bytes(5));
        return $this->database->insert(
            "INSERT INTO articles(author_id,title,slug,lead,body,status,access_mode,price_minor,currency,is_premium,is_unique,pricing_status,published_at,created_at,updated_at)
             VALUES(:author,:title,:slug,'Lead testowy','Treść testowa','published','free',0,'PLN',0,0,'free',NOW(),NOW(),NOW())",
            ['author' => $authorId, 'title' => $title, 'slug' => $slug],
        );
    }

    private function adminId(): int
    {
        return (int)$this->database->cell("SELECT user_id FROM user_roles WHERE role='admin' ORDER BY user_id LIMIT 1");
    }

    private function ordinaryUserId(): int
    {
        $id = (int)$this->database->cell(
            "SELECT u.id FROM users u WHERE u.status='active'
             AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role='admin')
             ORDER BY u.id LIMIT 1",
        );
        if ($id > 0) {
            return $id;
        }
        $created = (new AuthService($this->database))->register([
            'email' => 'campaign-user-' . bin2hex(random_bytes(5)) . '@phpunit.example',
            'phone' => '',
            'password' => 'Phpunit-Campaign-User-2026!',
            'display_name' => 'Użytkownik kampanii PHPUnit',
            'role' => 'reader',
        ]);
        return (int)$created['id'];
    }
}
