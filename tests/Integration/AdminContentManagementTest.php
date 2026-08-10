<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\CampaignService;
use App\Services\CategoryService;
use App\Services\FinancialService;
use App\Services\LedgerService;
use App\Services\MainBannerService;
use App\Services\PaymentService;
use App\Services\SurveyService;
use App\Services\AuthService;
use App\Services\TalentService;
use App\Jobs\EarningsJobHandler;
use App\Services\DurableJobQueue;
use App\Services\DurableJobWorker;
use App\Services\EarningsQueueService;

final class AdminContentManagementTest extends DatabaseTestCase
{
    public function testCategoryCreateUpdateTranslationsAndDeleteWorkflow(): void
    {
        $service = new CategoryService($this->database);
        $id = $service->create('  PHPUnit Kategoria  ');

        $service->update($id, [
            'name' => 'Kategoria po zmianie',
            'show_in_menu' => 1,
            'is_active' => 1,
            'menu_order' => 777,
            'translations' => [
                'en' => ['name' => 'Updated category', 'description' => 'Test'],
                '../bad' => ['name' => 'Nie zapisuj'],
            ],
        ]);

        $category = $this->database->one('SELECT * FROM categories WHERE id=:id', ['id' => $id]);
        self::assertSame('Kategoria po zmianie', $category['name']);
        self::assertSame(777, (int)$category['menu_order']);
        self::assertSame(
            'Updated category',
            $this->database->cell(
                'SELECT name FROM category_translations WHERE category_id=:id AND language=\'en\'',
                ['id' => $id]
            )
        );
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM category_translations WHERE category_id=:id',
            ['id' => $id]
        ));
        self::assertTrue($service->delete($id));
        self::assertNull($this->database->one('SELECT id FROM categories WHERE id=:id', ['id' => $id]));
    }

    public function testCategoryAndCampaignUpdatesRejectMissingRecords(): void
    {
        $category = new CategoryService($this->database);
        try {
            $category->update(PHP_INT_MAX, ['name' => 'Nie istnieje']);
            self::fail('Aktualizacja nieistniejącej kategorii powinna zostać odrzucona.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Nie znaleziono kategorii', $error->getMessage());
        }

        $campaign = $this->campaignService();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nie znaleziono kampanii');
        $campaign->update(PHP_INT_MAX, $this->adminId(), ['name' => 'Nie istnieje']);
    }

    public function testSurveyCannotBecomeActiveWithoutQuestionsAndChecksQuestionOwnership(): void
    {
        $service = new SurveyService($this->database);
        try {
            $service->createSurvey($this->adminId(), [
                'title' => 'Niepoprawnie aktywna',
                'status' => 'active',
            ]);
            self::fail('Nowa ankieta bez pytań nie może być aktywna.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('dodaj co najmniej jedno pytanie', $error->getMessage());
        }

        $firstId = $service->createSurvey($this->adminId(), $this->surveyPayload('Pierwsza'));
        $secondId = $service->createSurvey($this->adminId(), $this->surveyPayload('Druga'));
        $questionId = $service->addQuestion($firstId, [
            'question_text' => 'Czy test działa?',
            'question_type' => 'single_choice',
            'options' => "Tak\nNie",
            'is_required' => '1',
        ]);

        try {
            $service->deleteQuestion($questionId, $secondId);
            self::fail('Nie wolno usuwać pytania przez identyfikator innej ankiety.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('wybranej ankiecie', $error->getMessage());
        }

        $payload = $this->surveyPayload('Pierwsza aktywna');
        $payload['status'] = 'active';
        $service->updateSurvey($firstId, $payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ostatniego pytania');
        $service->deleteQuestion($questionId, $firstId);
    }

    public function testSurveySubmissionBooksRewardAtomicallyAndRespectsBudget(): void
    {
        $this->database->query(
            'UPDATE background_jobs SET available_at=' . $this->database->nowPlus(1, 'day') . '
             WHERE queue_name=\'earnings.critical\' AND status IN (\'queued\',\'retry\')'
        );
        $service = new SurveyService($this->database);
        $this->database->query(
            "UPDATE activity_reward_rules SET is_active=1,points_amount=37,amount_minor=555,daily_limit=1 WHERE activity_type='survey_reward'"
        );
        $surveyId = $service->createSurvey($this->adminId(), [
            'title' => 'Ankieta budżetowa',
            'status' => 'draft',
            'budget' => '1,00',
            'reward_amount' => '1,00',
            'max_responses' => 1,
        ]);
        $questionId = $service->addQuestion($surveyId, [
            'question_text' => 'Odpowiedź?',
            'question_type' => 'single_choice',
            'options' => "A\nB",
            'is_required' => '1',
        ]);
        $service->updateSurvey($surveyId, [
            'title' => 'Ankieta budżetowa',
            'status' => 'active',
            'budget' => '1,00',
            'reward_amount' => '1,00',
            'max_responses' => 1,
        ]);

        $userId = $this->ordinaryUserId();
        $pointsBefore = (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $userId]);
        $_POST['answer_seconds'] = 30;
        $responseId = $service->submitResponse($surveyId, $userId, [$questionId => 'A']);

        $response = $this->database->one('SELECT * FROM survey_responses WHERE id=:id', ['id' => $responseId]);
        self::assertSame('pending', $response['reward_status']);
        $worker = new DurableJobWorker(
            new DurableJobQueue($this->database),
            new EarningsJobHandler($this->database),
            EarningsQueueService::QUEUE,
            'phpunit-earnings-worker',
        );
        $workerResult = $worker->runOne();
        $queuedJob = $this->database->one(
            'SELECT status,last_error FROM background_jobs WHERE queue_name=\'earnings.critical\' AND idempotency_key=:key',
            ['key' => 'survey-response:' . $responseId]
        );
        self::assertSame(1, $workerResult['completed'], json_encode(['worker' => $workerResult, 'job' => $queuedJob], JSON_UNESCAPED_UNICODE));
        $response = $this->database->one('SELECT * FROM survey_responses WHERE id=:id', ['id' => $responseId]);
        self::assertSame('paid', $response['reward_status']);
        self::assertGreaterThan(0, (int)$response['wallet_transaction_id']);
        self::assertSame(100, (int)$response['reward_amount_minor']);
        self::assertSame($pointsBefore + 37, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $userId]));
        $talentLog = $this->database->one(
            "SELECT * FROM activity_reward_logs
             WHERE user_id=:user AND activity_type='survey_reward'
               AND reference_type='survey_response' AND reference_id=:response",
            ['user' => $userId, 'response' => $responseId]
        );
        self::assertNotNull($talentLog);
        self::assertSame(37, (int)$talentLog['points_amount']);
        self::assertSame(0, (int)$talentLog['amount_minor']);
        $moneyTransaction = $this->database->one(
            'SELECT amount_minor,points,ref_type,ref_id FROM wallet_transactions WHERE id=:id',
            ['id' => (int)$response['wallet_transaction_id']]
        );
        self::assertSame(100, (int)$moneyTransaction['amount_minor']);
        self::assertSame(0, (int)$moneyTransaction['points']);
        self::assertSame('survey_response', (string)$moneyTransaction['ref_type']);
        self::assertSame($responseId, (int)$moneyTransaction['ref_id']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('limit odpowiedzi');
        $service->submitResponse($surveyId, $this->anotherOrdinaryUserId($userId), [$questionId => 'B']);
    }

    public function testInactiveSurveyTalentRuleDoesNotBlockSnapshottedPlnReward(): void
    {
        $this->database->query(
            'UPDATE background_jobs SET available_at=' . $this->database->nowPlus(1, 'day') . "
             WHERE queue_name='earnings.critical' AND status IN ('queued','retry')"
        );
        $this->database->query(
            "UPDATE activity_reward_rules SET is_active=0,points_amount=999,amount_minor=0,daily_limit=0 WHERE activity_type='survey_reward'"
        );
        $service = new SurveyService($this->database);
        $surveyId = $service->createSurvey($this->adminId(), [
            'title' => 'Ankieta bez TT',
            'status' => 'draft',
            'budget' => '2,00',
            'reward_amount' => '2,00',
            'max_responses' => 1,
        ]);
        $questionId = $service->addQuestion($surveyId, [
            'question_text' => 'Odpowiedź?',
            'question_type' => 'single_choice',
            'options' => "A\nB",
            'is_required' => '1',
        ]);
        $service->updateSurvey($surveyId, [
            'title' => 'Ankieta bez TT',
            'status' => 'active',
            'budget' => '2,00',
            'reward_amount' => '2,00',
            'max_responses' => 1,
        ]);

        $userId = $this->ordinaryUserId();
        $pointsBefore = (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $userId]);
        $_POST['answer_seconds'] = 30;
        $responseId = $service->submitResponse($surveyId, $userId, [$questionId => 'A']);
        $worker = new DurableJobWorker(
            new DurableJobQueue($this->database),
            new EarningsJobHandler($this->database),
            EarningsQueueService::QUEUE,
            'phpunit-survey-no-tt-worker',
        );
        self::assertSame(1, $worker->runOne()['completed']);

        $response = $this->database->one('SELECT * FROM survey_responses WHERE id=:id', ['id' => $responseId]);
        self::assertSame('paid', (string)$response['reward_status']);
        self::assertGreaterThan(0, (int)$response['wallet_transaction_id']);
        self::assertSame(200, (int)$this->database->cell(
            'SELECT amount_minor FROM wallet_transactions WHERE id=:id',
            ['id' => (int)$response['wallet_transaction_id']]
        ));
        self::assertSame($pointsBefore, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $userId]));
        self::assertSame(0, (int)$this->database->cell(
            "SELECT COUNT(*) FROM activity_reward_logs
             WHERE user_id=:user AND activity_type='survey_reward'
               AND reference_type='survey_response' AND reference_id=:response",
            ['user' => $userId, 'response' => $responseId]
        ));
    }

    public function testPpvIsNotAnAvailableCampaignType(): void
    {
        $service = $this->campaignService();
        self::assertArrayNotHasKey('ppv', $service->typeDefinitions());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prawidłowy typ kampanii');
        $service->create($this->adminId(), [
            'client_name' => 'Klient PHPUnit',
            'name' => 'PPV bez dowodu',
            'type' => 'ppv',
            'status' => 'active',
            'budget' => '100,00',
            'cost_per_view' => '2,00',
            'budget_confirmed' => '1',
        ]);
    }

    public function testVerifiedCampaignClickSnapshotsTalentAndDuplicateDoesNotConsumeBudget(): void
    {
        $this->database->query(
            "UPDATE activity_reward_rules
             SET points_amount=10,amount_minor=0,daily_limit=0,is_active=1
             WHERE activity_type='ad_click_reward'"
        );
        $service = $this->campaignService();
        $campaignId = $service->create($this->adminId(), [
            'client_name' => 'Reklamodawca PHPUnit',
            'client_email' => 'ads@example.test',
            'name' => 'Kliknięcie PHPUnit',
            'type' => 'ad_click',
            'status' => 'active',
            'target_url' => 'https://example.test/landing',
            'creative_path' => '/uploads/campaigns/phpunit-banner.webp',
            'creative_mime' => 'image/webp',
            'budget' => '10,00',
            'cost_per_click' => '1,50',
            'budget_confirmed' => '1',
        ]);

        $result = $service->recordClick($this->ordinaryUserId(), $campaignId);
        self::assertNotNull($result);
        $event = $this->database->one(
            'SELECT * FROM campaign_events WHERE id=:id',
            ['id' => (int)$result['event_id']]
        );
        self::assertSame('verified', $event['verification_status']);
        self::assertSame('server_redirect', $event['proof_type']);
        self::assertSame(150, (int)$event['cost_minor']);
        self::assertSame(10, (int)$event['talent_points_snapshot']);
        self::assertSame(0, (int)$event['reward_points']);
        self::assertNotEmpty($event['talent_job_public_id']);

        self::assertNull($service->recordClick($this->ordinaryUserId(), $campaignId));
        self::assertSame(150, (int)$this->database->cell(
            "SELECT SUM(cost_minor) FROM campaign_events WHERE campaign_id=:id AND verification_status='verified'",
            ['id' => $campaignId]
        ));
        self::assertSame(1, (int)$this->database->cell(
            'SELECT duplicate_attempts_count FROM campaigns WHERE id=:id',
            ['id' => $campaignId]
        ));

        $this->database->query(
            "UPDATE activity_reward_rules SET points_amount=1,is_active=0 WHERE activity_type='ad_click_reward'"
        );
        $worker = new DurableJobWorker(
            new DurableJobQueue($this->database),
            new EarningsJobHandler($this->database),
            EarningsQueueService::QUEUE,
            'phpunit-campaign-worker',
        );
        self::assertSame(1, $worker->runOne()['completed']);
        self::assertSame(10, (int)$this->database->cell(
            'SELECT reward_points FROM campaign_events WHERE id=:id',
            ['id' => (int)$result['event_id']]
        ));
        self::assertSame(10, (int)$this->database->cell(
            "SELECT points_amount FROM activity_reward_logs
             WHERE activity_type='ad_click_reward' AND reference_type='campaign_event' AND reference_id=:id",
            ['id' => (int)$result['event_id']]
        ));
    }

    public function testManualTalentApprovalRejectsZeroOrNegativeReward(): void
    {
        $_SESSION['user_id'] = $this->adminId();
        $_SESSION['role'] = 'admin';
        $service = new FinancialService($this->database);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('większe od zera');
        $service->requestApproval(
            'manual_reward',
            0,
            'TT',
            0,
            $this->ordinaryUserId(),
            ['account_type' => 'points', 'points' => 0, 'description' => 'Błędna nagroda'],
            'Błędna nagroda'
        );
    }

    public function testManualPaymentIsMarkedPaidAtomicallyAndMissingPaymentFails(): void
    {
        $service = new PaymentService($this->database);
        $paymentId = $service->createPayment(
            $this->ordinaryUserId(),
            'manual',
            'donation',
            'pending',
            500,
            ['payer_email' => 'phpunit@example.test']
        );

        $service->markPaid($paymentId, 'PHPUNIT-EXTERNAL');

        $payment = $this->database->one('SELECT * FROM payments WHERE id=:id', ['id' => $paymentId]);
        self::assertSame('paid', $payment['status']);
        self::assertSame('PHPUNIT-EXTERNAL', $payment['external_id']);
        self::assertSame(1, (int)$this->database->cell(
            'SELECT COUNT(*) FROM payment_events WHERE payment_id=:id AND event_type=\'marked_paid\'',
            ['id' => $paymentId]
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nie znaleziono płatności');
        $service->markPaid(PHP_INT_MAX);
    }

    public function testMainBannerAcceptsSafeLinksAndRejectsJavascriptUrls(): void
    {
        $service = new MainBannerService($this->database);
        $service->updateFromAdmin([
            'button_url' => '/register',
            'image_path' => 'https://example.test/banner.webp',
            'is_active' => '1',
            'translations' => [
                'pl' => [
                    'kicker' => 'Test',
                    'title' => 'Baner PHPUnit',
                    'lead_text' => 'Lead',
                    'body_text' => 'Opis',
                    'button_label' => 'Dołącz',
                ],
            ],
        ], ['pl']);

        $banner = $service->forAdmin(['pl']);
        self::assertSame('/register', $banner['button_url']);
        self::assertSame('https://example.test/banner.webp', $banner['image_path']);
        self::assertSame('Baner PHPUnit', $banner['translations']['pl']['title']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nieprawidłowy adres');
        $service->updateFromAdmin([
            'button_url' => 'javascript:alert(1)',
            'image_path' => '/safe.webp',
        ], ['pl']);
    }

    private function campaignService(): CampaignService
    {
        $financial = new FinancialService($this->database);
        $ledger = new LedgerService($this->database, $financial);
        return new CampaignService(
            $this->database,
            new TalentService($this->database, $ledger)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function surveyPayload(string $title): array
    {
        return [
            'title' => $title,
            'status' => 'draft',
            'budget' => '10,00',
            'reward_amount' => '0,50',
            'max_responses' => 10,
        ];
    }

    private function adminId(): int
    {
        $id = (int)$this->database->cell(
            'SELECT user_id FROM user_roles WHERE role=\'admin\' ORDER BY user_id LIMIT 1'
        );
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function ordinaryUserId(): int
    {
        $id = (int)$this->database->cell(
            'SELECT u.id FROM users u
             WHERE u.status=\'active\'
               AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'admin\')
             ORDER BY u.id LIMIT 1'
        );
        if ($id <= 0) {
            $created = (new AuthService($this->database))->register([
                'email' => 'ordinary-user-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'phone' => '',
                'password' => 'Phpunit-Ordinary-User-2026!',
                'display_name' => 'Zwykły użytkownik PHPUnit',
                'role' => 'reader',
            ]);
            $id = (int)$created['id'];
        }
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function anotherOrdinaryUserId(int $except): int
    {
        $id = (int)$this->database->cell(
            'SELECT u.id FROM users u
             WHERE u.status=\'active\' AND u.id<>:except
               AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'admin\')
             ORDER BY u.id LIMIT 1',
            ['except' => $except]
        );
        if ($id <= 0) {
            $created = (new AuthService($this->database))->register([
                'email' => 'second-user-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'phone' => '',
                'password' => 'Phpunit-Second-User-2026!',
                'display_name' => 'Drugi użytkownik PHPUnit',
                'role' => 'reader',
            ]);
            $id = (int)$created['id'];
        }
        self::assertGreaterThan(0, $id);
        return $id;
    }
}
