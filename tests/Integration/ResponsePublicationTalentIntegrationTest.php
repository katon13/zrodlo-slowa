<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ArticleService;
use App\Services\Dors3MobileOperationExecutor;
use App\Services\Dors3OperationFingerprintService;
use App\Services\FinancialService;
use App\Services\LedgerService;
use App\Services\PayoutMethodService;
use App\Services\PayoutService;
use App\Services\ResponsePublicationService;
use App\Services\ResponseSubmissionDepositException;
use App\Services\RoleService;
use App\Services\TalentService;
use App\Services\UserService;

final class ResponsePublicationTalentIntegrationTest extends DatabaseTestCase
{
    public function testPublicationSnapshotsActiveRuleAndWorkerIgnoresLaterAdminChange(): void
    {
        $authorId = $this->user('response-author');
        $sourceId = $this->publishedSource($authorId);
        $this->database->query(
            "UPDATE activity_reward_rules
             SET is_active=1,points_amount=123,amount_minor=0,daily_limit=0
             WHERE activity_type='response_publication_bonus'"
        );

        $articles = new ArticleService($this->database);
        $responseId = $articles->createResponseDraft($authorId, $sourceId, $this->content('Pierwsza polemika'));
        $this->publish($articles, $responseId, $authorId);

        $article = $this->database->one('SELECT * FROM articles WHERE id=:id', ['id' => $responseId]);
        self::assertNotNull($article);
        self::assertTrue((bool)$article['response_reward_qualified']);
        self::assertSame(123, (int)$article['response_reward_points']);
        self::assertNotSame('', (string)$article['response_reward_job_public_id']);

        $job = $this->database->one(
            'SELECT * FROM background_jobs WHERE public_id=:id',
            ['id' => $article['response_reward_job_public_id']]
        );
        self::assertNotNull($job);
        $payload = json_decode((string)$job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['response_rule_qualified']);
        self::assertSame(123, $payload['response_points_amount']);

        $this->database->query(
            "UPDATE activity_reward_rules
             SET is_active=0,points_amount=999
             WHERE activity_type='response_publication_bonus'"
        );
        $first = $this->awardJob($job);
        $retry = $this->awardJob($job);

        self::assertSame('awarded', $first['decision']);
        self::assertSame(123, $first['points']);
        self::assertSame(0, $first['amount_minor']);
        self::assertSame('duplicate', $retry['decision']);
        self::assertSame(123, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $authorId]));
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM activity_reward_logs
             WHERE activity_type='response_publication_bonus' AND reference_id=:id",
            ['id' => $responseId]
        ));

        $revisionId = $articles->updateDraft($responseId, $authorId, $this->content('Pierwsza polemika po korekcie'));
        self::assertNotSame($responseId, $revisionId);
        $this->publish($articles, $revisionId, $authorId);
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM background_jobs
             WHERE payload_json->>'activity_type'='response_publication_bonus'
               AND payload_json->>'reference_id'=:reference",
            ['reference' => (string)$responseId]
        ));
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM activity_reward_logs
             WHERE activity_type='response_publication_bonus' AND reference_id=:id",
            ['id' => $responseId]
        ));
    }

    public function testInactiveRuleSnapshotCannotBecomeEligibleWhenAdminEnablesItLater(): void
    {
        $authorId = $this->user('inactive-response');
        $sourceId = $this->publishedSource($authorId);
        $this->database->query(
            "UPDATE activity_reward_rules SET is_active=0,points_amount=500,amount_minor=0
             WHERE activity_type='response_publication_bonus'"
        );

        $articles = new ArticleService($this->database);
        $responseId = $articles->createResponseDraft($authorId, $sourceId, $this->content('Polemika bez aktywnej reguły'));
        $this->publish($articles, $responseId, $authorId);
        $article = $this->database->one('SELECT * FROM articles WHERE id=:id', ['id' => $responseId]);
        self::assertFalse((bool)$article['response_reward_qualified']);
        self::assertSame(0, (int)$article['response_reward_points']);

        $this->database->query(
            "UPDATE activity_reward_rules SET is_active=1,points_amount=777
             WHERE activity_type='response_publication_bonus'"
        );
        $job = $this->database->one('SELECT * FROM background_jobs WHERE public_id=:id', ['id' => $article['response_reward_job_public_id']]);
        $result = $this->awardJob($job);
        self::assertSame('snapshot_ineligible', $result['decision']);
        self::assertSame(0, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $authorId]));
    }

    public function testDraftSubmissionAndRejectionNeverCreateRewardJob(): void
    {
        $authorId = $this->user('draft-response');
        $sourceId = $this->publishedSource($authorId);
        $articles = new ArticleService($this->database);
        $responseId = $articles->createResponseDraft($authorId, $sourceId, $this->content('Szkic polemiki'));
        $articles->submit($responseId, $authorId);
        $articles->setStatus($responseId, 'rejected', $authorId);

        self::assertSame(0, (int)$this->database->cell(
            "SELECT COUNT(*) FROM background_jobs
             WHERE payload_json->>'activity_type'='response_publication_bonus'
               AND payload_json->>'reference_id'=:reference",
            ['reference' => (string)$responseId]
        ));
    }

    public function testDepositIsSnapshottedOnceForfeitedAndReturnedAfterLaterPublication(): void
    {
        $authorId = $this->user('response-deposit');
        $this->database->query('UPDATE wallets SET points_balance=200 WHERE user_id=:id', ['id' => $authorId]);
        $sourceId = $this->publishedSource($authorId);
        $this->database->query(
            "UPDATE activity_reward_rules
             SET is_active=1,points_amount=50,amount_minor=0,daily_limit=0,submission_deposit_points=80
             WHERE activity_type='response_publication_bonus'"
        );

        $articles = new ArticleService($this->database);
        $responseId = $articles->createResponseDraft($authorId, $sourceId, $this->content('Polemika z kaucją'));
        $articles->submit($responseId, $authorId);

        self::assertSame(120, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $authorId]));
        $held = $this->database->one('SELECT * FROM articles WHERE id=:id', ['id' => $responseId]);
        self::assertSame(80, (int)$held['response_deposit_points']);
        self::assertSame('held', (string)$held['response_deposit_status']);
        self::assertNotNull($held['response_deposit_debit_transaction_id']);
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM wallet_transactions WHERE type='response_submission_deposit_hold' AND ref_id=:id",
            ['id' => $responseId]
        ));

        $this->database->query(
            "UPDATE activity_reward_rules SET submission_deposit_points=999
             WHERE activity_type='response_publication_bonus'"
        );
        try {
            $articles->submit($responseId, $authorId);
            self::fail('Ponowienie requestu dla wysłanej polemiki powinno zostać odrzucone.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('aktualnego statusu', $error->getMessage());
        }
        self::assertSame(120, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $authorId]));

        $articles->setStatus($responseId, 'rejected', $authorId);
        $platformId = (int)$this->database->cell("SELECT id FROM users WHERE email='platform@zrodlo-slowa.local'");
        self::assertGreaterThan(0, $platformId);
        self::assertSame(80, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $platformId]));
        self::assertSame('forfeited', (string)$this->database->cell('SELECT response_deposit_status FROM articles WHERE id=:id', ['id' => $responseId]));

        $sameResponseId = $articles->updateDraft($responseId, $authorId, $this->content('Polemika poprawiona'));
        self::assertSame($responseId, $sameResponseId);
        $articles->submit($responseId, $authorId);
        self::assertSame(120, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $authorId]));
        self::assertSame(1, (int)$this->database->cell(
            "SELECT COUNT(*) FROM wallet_transactions WHERE type='response_submission_deposit_hold' AND ref_id=:id",
            ['id' => $responseId]
        ));

        $articles->setStatus($responseId, 'review', $authorId);
        $articles->setStatus($responseId, 'approved', $authorId);
        $articles->setStatus($responseId, 'published', $authorId);
        $settled = $this->database->one('SELECT * FROM articles WHERE id=:id', ['id' => $responseId]);
        self::assertSame(80, (int)$settled['response_deposit_points']);
        self::assertSame('refunded', (string)$settled['response_deposit_status']);
        self::assertNotNull($settled['response_deposit_reversal_transaction_id']);
        self::assertNotNull($settled['response_deposit_refund_transaction_id']);
        self::assertSame(200, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $authorId]));
        self::assertSame(0, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $platformId]));
    }

    public function testInsufficientDepositLeavesDraftAndWalletUntouched(): void
    {
        $authorId = $this->user('response-deposit-insufficient');
        $this->database->query('UPDATE wallets SET points_balance=20 WHERE user_id=:id', ['id' => $authorId]);
        $sourceId = $this->publishedSource($authorId);
        $this->database->query(
            "UPDATE activity_reward_rules SET submission_deposit_points=80
             WHERE activity_type='response_publication_bonus'"
        );
        $articles = new ArticleService($this->database);
        $responseId = $articles->createResponseDraft($authorId, $sourceId, $this->content('Polemika bez środków'));

        try {
            $articles->submit($responseId, $authorId);
            self::fail('Brak TT powinien zablokować wysłanie.');
        } catch (ResponseSubmissionDepositException $error) {
            self::assertSame(80, $error->requiredPoints);
        }

        $article = $this->database->one('SELECT * FROM articles WHERE id=:id', ['id' => $responseId]);
        self::assertSame('draft', (string)$article['status']);
        self::assertNull($article['response_deposit_status']);
        self::assertNull($article['response_deposit_points']);
        self::assertSame(20, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $authorId]));
        self::assertSame(0, (int)$this->database->cell(
            "SELECT COUNT(*) FROM wallet_transactions WHERE ref_type='response_publication' AND ref_id=:id",
            ['id' => $responseId]
        ));
    }

    public function testPaidSourceMustBeAccessibleBeforeResponseDraftCanBeCreated(): void
    {
        $sourceAuthorId = $this->user('paid-source-author');
        $responderId = $this->user('paid-source-responder');
        $sourceId = $this->publishedSource($sourceAuthorId);
        $this->database->query(
            "UPDATE articles SET access_mode='paid',price_minor=2500,pricing_status='priced' WHERE id=:id",
            ['id' => $sourceId]
        );
        $articles = new ArticleService($this->database);

        try {
            $articles->createResponseDraft($responderId, $sourceId, $this->content('Polemika bez zakupu'));
            self::fail('Płatny tekst bez aktywnego dostępu nie może przyjąć polemiki.');
        } catch (\RuntimeException $error) {
            self::assertSame('response_source_access_required', $error->getMessage());
        }

        self::assertTrue($articles->grantAccess($responderId, $sourceId, null, 'payment', 24));
        $responseId = $articles->createResponseDraft($responderId, $sourceId, $this->content('Polemika po zakupie'));
        self::assertGreaterThan(0, $responseId);
        self::assertSame($sourceId, (int)$this->database->cell('SELECT response_to_article_id FROM articles WHERE id=:id', ['id' => $responseId]));
    }

    public function testCommentatorGetsTalentAndWalletButNeverPayoutOrNormalAuthorWriting(): void
    {
        $userId = $this->database->insert(
            "INSERT INTO users(email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,created_at)
             VALUES(:email,:hash,'Komentator PHPUnit','active',1,0,0,1,NOW())",
            ['email' => 'commentator-' . bin2hex(random_bytes(6)) . '@phpunit.example', 'hash' => password_hash('PHPUnit-Response-2026!', PASSWORD_DEFAULT)]
        );
        $this->database->query("INSERT INTO user_roles(user_id,role) VALUES(:id,'reader')", ['id' => $userId]);

        (new UserService($this->database))->setPrimaryRole($userId, RoleService::ROLE_COMMENTATOR);
        $state = (new UserService($this->database))->findUserStatus($userId);

        self::assertStringContainsString('commentator', (string)$state['roles']);
        self::assertSame(0, (int)$state['can_write']);
        self::assertSame(1, (int)$state['talent_enabled']);
        self::assertSame(1, (int)$state['wallet_enabled']);
        self::assertSame(0, (int)$state['payout_enabled']);
        self::assertSame(1, (int)$this->database->cell('SELECT COUNT(*) FROM wallets WHERE user_id=:id', ['id' => $userId]));
        $responses = new ResponsePublicationService($this->database);
        self::assertTrue($responses->eligibility($userId)['can_respond']);
        self::assertSame('direct_response', $responses->submissionMode($userId, true));

        // Obrona warstwowa: nawet ręczne uszkodzenie flagi nie może otworzyć
        // komentatorowi metod ani wniosków wypłaty pieniężnej.
        $this->database->query('UPDATE users SET payout_enabled=1 WHERE id=:id', ['id' => $userId]);
        foreach ([
            fn() => (new PayoutMethodService($this->database))->create($userId, 'bank', 'Test', 'PL00TEST'),
            fn() => (new PayoutService($this->database))->request($userId, 1000),
        ] as $operation) {
            try {
                $operation();
                self::fail('Komentator nie może uzyskać dostępu do wypłaty PLN.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('komentatora', $error->getMessage());
            }
        }

        $sourceId = $this->publishedSource($userId);
        $responseId = $responses->createDraft($userId, $sourceId, $this->content('Polemika komentatora'));
        $responses->submit($userId, $responseId);
        self::assertSame('submitted', (string)$this->database->cell(
            'SELECT status FROM articles WHERE id=:id',
            ['id' => $responseId]
        ));
    }

    public function testApprovedAuthorAlwaysUsesAuthorSubmissionModeEvenWithCommentatorRole(): void
    {
        $authorId = $this->user('dual-response-role');
        $this->database->query(
            "INSERT INTO user_roles(user_id,role) VALUES(:id,'commentator') ON CONFLICT(user_id,role) DO NOTHING",
            ['id' => $authorId]
        );

        $responses = new ResponsePublicationService($this->database);
        self::assertSame(RoleService::ROLE_AUTHOR, $responses->eligibility($authorId)['role']);
        self::assertSame('dors3_author', $responses->submissionMode($authorId, true));
        self::assertSame('direct_response', $responses->submissionMode($authorId, false));
    }

    public function testExistingDors3ArticleSubmitOperationSubmitsAnAuthorResponse(): void
    {
        $authorId = $this->user('dors3-response-author');
        $this->database->insert(
            "INSERT INTO author_agreements(public_id,user_id,organization_id,status,valid_from,terms_version,created_at,updated_at)
             VALUES(:public,:user,'zrodlo-slowa','active',NOW(),'phpunit-v1',NOW(),NOW())",
            ['public' => $this->uuid(), 'user' => $authorId]
        );
        $sourceId = $this->publishedSource($authorId);
        $responseId = (new ResponsePublicationService($this->database))->createDraft(
            $authorId,
            $sourceId,
            $this->content('Polemika podpisana w 3DORS')
        );
        $issuedAt = time();
        $fingerprint = (new Dors3OperationFingerprintService($this->database))
            ->articleSubmit($responseId, $authorId, $issuedAt);

        (new Dors3MobileOperationExecutor())->execute(
            $this->database,
            [
                'action_type' => 'article.submit',
                'issued_at_epoch' => $issuedAt,
                'user_id' => $authorId,
                'action_fingerprint' => $fingerprint['fingerprint'],
            ],
            ['article_id' => $responseId, 'author_id' => $authorId]
        );

        self::assertSame('submitted', (string)$this->database->cell(
            'SELECT status FROM articles WHERE id=:id',
            ['id' => $responseId]
        ));
        self::assertSame(0, (int)$this->database->cell(
            "SELECT COUNT(*) FROM background_jobs
             WHERE payload_json->>'activity_type'='response_publication_bonus'
               AND payload_json->>'reference_id'=:reference",
            ['reference' => (string)$responseId]
        ));
    }

    public function testUnverifiedTalentRulesCannotBeActivatedFromAdminConfiguration(): void
    {
        $talent = new TalentService(
            $this->database,
            new LedgerService($this->database, new FinancialService($this->database)),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nie ma jeszcze wiarygodnego punktu wyzwolenia');
        $talent->updateRule('share_bonus', 10, 0, 1, true);
    }

    private function publish(ArticleService $articles, int $articleId, int $actorId): void
    {
        $article = $articles->findAnyWithAuthor($articleId);
        if ((string)$article['status'] === 'draft') {
            $articles->submit($articleId, $actorId);
        }
        $articles->setStatus($articleId, 'review', $actorId);
        $articles->setStatus($articleId, 'approved', $actorId);
        $articles->setStatus($articleId, 'published', $actorId);
    }

    /** @return array<string,mixed> */
    private function awardJob(array $job): array
    {
        $payload = json_decode((string)$job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        return (new TalentService(
            $this->database,
            new LedgerService($this->database, new FinancialService($this->database)),
        ))->award(
            (int)$payload['user_id'],
            (string)$payload['activity_type'],
            (string)$payload['reference_type'],
            (int)$payload['reference_id'],
            $payload + [
                'job_public_id' => (string)$job['public_id'],
                'job_idempotency_key' => (string)$job['idempotency_key'],
            ],
        );
    }

    /** @return array{title:string,lead:string,body:string,source_language:string} */
    private function content(string $title): array
    {
        return [
            'title' => $title,
            'lead' => 'Merytoryczna odpowiedź na opublikowany tekst.',
            'body' => 'To jest podpisana, samodzielna odpowiedź publikacją. Zawiera argumenty i przechodzi przez pełną redakcję.',
            'source_language' => 'pl',
        ];
    }

    private function publishedSource(int $authorId): int
    {
        return $this->database->insert(
            "INSERT INTO articles(author_id,title,slug,lead,body,status,access_mode,price_minor,pricing_status,source_language,published_at,created_at,updated_at)
             VALUES(:author,'Tekst źródłowy',:slug,'Lead tekstu źródłowego','Treść tekstu źródłowego','published','free',0,'free','pl',NOW(),NOW(),NOW())",
            ['author' => $authorId, 'slug' => 'tekst-zrodlowy-' . bin2hex(random_bytes(5))]
        );
    }

    private function user(string $prefix): int
    {
        $userId = $this->database->insert(
            "INSERT INTO users(email,password_hash,display_name,status,can_write,talent_enabled,wallet_enabled,payout_enabled,created_at)
             VALUES(:email,:hash,:name,'active',1,1,1,1,NOW())",
            [
                'email' => $prefix . '-' . bin2hex(random_bytes(6)) . '@phpunit.example',
                'hash' => password_hash('PHPUnit-Response-2026!', PASSWORD_DEFAULT),
                'name' => $prefix,
            ]
        );
        $this->database->query("INSERT INTO user_roles(user_id,role) VALUES(:id,'author')", ['id' => $userId]);
        $this->database->query("INSERT INTO wallets(user_id,main_available_minor,main_reserved_minor,slowo_available_minor,slowo_reserved_minor,available_minor,pending_minor,reserved_minor,points_balance,currency,created_at) VALUES(:id,0,0,0,0,0,0,0,0,'PLN',NOW())", ['id' => $userId]);
        return $userId;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
