<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\SlowoSnajperConfig;
use App\Infrastructure\Valkey\NullQueueSignal;
use App\Jobs\EarningsJobHandler;
use App\Services\AppReferralService;
use App\Services\AuthService;
use App\Services\DurableJobQueue;
use App\Services\EarningsJobDispatcher;
use App\Services\MailService;

final class AppReferralPromotionIntegrationTest extends DatabaseTestCase
{
    public function testAdministratorCanDisableAndEnablePromotion(): void
    {
        $adminId = $this->register('reader', 'promotion-admin');
        $service = $this->service();
        $input = [
            'reward_points' => 1000,
            'active_invitation_limit' => 3,
            'successful_referral_limit' => 3,
            'invitation_valid_days' => 30,
            'starts_at' => (new \DateTimeImmutable('-5 minutes', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d\TH:i'),
            'ends_at' => '',
        ];

        $disabled = $service->updatePromotion($adminId, $input);
        self::assertFalse($disabled['is_promoted']);
        self::assertNull($service->currentPromotion());

        $enabled = $service->updatePromotion($adminId, $input + ['is_promoted' => '1']);
        self::assertTrue($enabled['is_promoted']);
        self::assertNotNull($service->currentPromotion());
    }

    public function testInvitationSnapshotsRewardAndDeadLetterReleasesActiveSlot(): void
    {
        $inviter = $this->register('reader', 'inviter');
        $this->database->query(
            "UPDATE talent_promotions SET reward_points=1000,active_invitation_limit=3,
                successful_referral_limit=3,is_promoted=TRUE,starts_at=NOW(),ends_at=NULL
             WHERE code='app_referral'"
        );
        $service = $this->service();

        $first = $service->createInvitation($inviter, $this->email('first'));
        $second = $service->createInvitation($inviter, $this->email('second'));
        $service->createInvitation($inviter, $this->email('third'));

        self::assertSame(1000, $first['reward_points']);
        $this->expectException(\RuntimeException::class);
        try {
            $service->createInvitation($inviter, $this->email('blocked'));
        } finally {
            $this->database->query(
                "UPDATE mail_queue SET status='dead_letter',dead_lettered_at=NOW(),updated_at=NOW()
                 WHERE id=(SELECT mail_queue_id FROM app_referral_invitations WHERE id=:id)",
                ['id' => (int)$second['id']]
            );
            $this->database->query(
                "UPDATE talent_promotions SET reward_points=500 WHERE code='app_referral'"
            );
            $replacement = $service->createInvitation($inviter, $this->email('replacement'));
            self::assertSame(500, $replacement['reward_points']);
            self::assertSame(1000, (int)$this->database->cell(
                'SELECT reward_points FROM app_referral_invitations WHERE id=:id',
                ['id' => (int)$first['id']]
            ));
            self::assertSame('mail_dead_letter', (string)$this->database->cell(
                'SELECT status FROM app_referral_invitations WHERE id=:id',
                ['id' => (int)$second['id']]
            ));
        }
    }

    public function testFirstValidSessionQueuesBothRewardsAndTalentUsesInvitationSnapshot(): void
    {
        $inviter = $this->register('reader', 'source');
        $inviteeEmail = $this->email('invitee');
        $this->database->query(
            "UPDATE talent_promotions SET reward_points=1000,active_invitation_limit=3,
                successful_referral_limit=3,is_promoted=TRUE,starts_at=NOW(),ends_at=NULL
             WHERE code='app_referral'"
        );
        $service = $this->service();
        $invitation = $service->createInvitation($inviter, $inviteeEmail);
        $token = $this->tokenFromInvitation((int)$invitation['id']);
        $deviceId = hash('sha256', 'physical-device-fixture');

        $service->recordInstallation($token, $deviceId);
        $registration = $service->createRegistrationNonce($token, $deviceId);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', (string)$registration['registration_nonce']);
        self::assertNotSame($token, (string)$registration['registration_nonce']);
        $invitee = $this->register('author', 'invitee', $inviteeEmail);
        $service->consumeRegistrationNonce(
            (string)$registration['registration_nonce'],
            $invitee,
            $inviteeEmail,
        );
        $result = $service->completeFirstSession($token, $deviceId, $invitee);

        self::assertTrue($result['completed']);
        self::assertFalse($result['duplicate']);
        self::assertSame(2, (int)$this->database->cell(
            "SELECT COUNT(*) FROM background_jobs
             WHERE public_id IN (:inviter_job,:invitee_job)",
            [
                'inviter_job' => $result['inviter_job_public_id'],
                'invitee_job' => $result['invitee_job_public_id'],
            ]
        ));
        self::assertSame('reward_queued', (string)$this->database->cell(
            'SELECT status FROM app_referral_invitations WHERE id=:id',
            ['id' => (int)$invitation['id']]
        ));

        // Zmiana promocji po wysłaniu i zakolejkowaniu nie może zmienić wypłaty.
        $this->database->query("UPDATE talent_promotions SET reward_points=500 WHERE code='app_referral'");
        $handler = new EarningsJobHandler($this->database, SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)));
        foreach ([$result['inviter_job_public_id'], $result['invitee_job_public_id']] as $publicId) {
            $job = $this->database->one('SELECT * FROM background_jobs WHERE public_id=:id', ['id' => $publicId]);
            self::assertNotNull($job);
            $award = $handler->handle($job);
            self::assertTrue($award['awarded'], json_encode($award, JSON_UNESCAPED_UNICODE));
            self::assertSame(1000, $award['points']);
            self::assertSame(0, $award['amount_minor']);
        }

        self::assertSame(1000, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $inviter]));
        self::assertSame(1000, (int)$this->database->cell('SELECT points_balance FROM wallets WHERE user_id=:id', ['id' => $invitee]));
        self::assertSame('rewarded', (string)$this->database->cell(
            'SELECT status FROM app_referral_invitations WHERE id=:id',
            ['id' => (int)$invitation['id']]
        ));

        $duplicate = $service->completeFirstSession($token, $deviceId, $invitee);
        self::assertTrue($duplicate['duplicate']);
        self::assertSame(2, (int)$this->database->cell(
            "SELECT COUNT(*) FROM activity_reward_logs
             WHERE activity_type='app_referral_bonus' AND reference_type='app_referral_invitation' AND reference_id=:id",
            ['id' => (int)$invitation['id']]
        ));
    }

    public function testAccountCreatedBeforeInstallationCannotConsumeRegistrationNonce(): void
    {
        $inviter = $this->register('reader', 'install-order-source');
        $inviteeEmail = $this->email('install-order-invitee');
        $service = $this->service();
        $invitation = $service->createInvitation($inviter, $inviteeEmail);
        $token = $this->tokenFromInvitation((int)$invitation['id']);
        $invitee = $this->register('reader', 'install-order-existing', $inviteeEmail);
        $this->database->query(
            "UPDATE users SET created_at=NOW() - INTERVAL '1 minute' WHERE id=:id",
            ['id' => $invitee]
        );
        $deviceId = hash('sha256', 'install-order-device-fixture');

        $service->recordInstallation($token, $deviceId);
        $registration = $service->createRegistrationNonce($token, $deviceId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Konto musi zosta');
        $service->consumeRegistrationNonce(
            (string)$registration['registration_nonce'],
            $invitee,
            $inviteeEmail,
        );
    }

    public function testOverviewStopsAdvertisingPromotionAfterSuccessfulPoolIsExhausted(): void
    {
        $inviter = $this->register('reader', 'exhausted-source');
        $service = $this->service();

        for ($index = 1; $index <= 3; $index++) {
            $invitation = $service->createInvitation($inviter, $this->email('exhausted-' . $index));
            $this->database->query(
                "UPDATE app_referral_invitations SET status='reward_queued' WHERE id=:id",
                ['id' => (int)$invitation['id']]
            );
        }

        $overview = $service->userOverview($inviter);
        self::assertTrue($overview['pool_exhausted']);
        self::assertFalse($overview['can_invite']);
        self::assertNull($overview['promotion']);
        self::assertSame(3, $overview['successful_count']);
    }

    public function testOverviewStopsAdvertisingPromotionWhileAllActiveInvitationSlotsAreOccupied(): void
    {
        $inviter = $this->register('reader', 'active-pool-source');
        $service = $this->service();

        for ($index = 1; $index <= 3; $index++) {
            $service->createInvitation($inviter, $this->email('active-pool-' . $index));
        }

        $overview = $service->userOverview($inviter);
        self::assertTrue($overview['pool_exhausted']);
        self::assertFalse($overview['can_invite']);
        self::assertNull($overview['promotion']);
        self::assertSame(3, $overview['active_count']);
        self::assertSame(0, $overview['successful_count']);
    }

    public function testReferralRuleCannotBeChangedThroughGenericTalentSettings(): void
    {
        $talent = new \App\Services\TalentService(
            $this->database,
            new \App\Services\LedgerService(
                $this->database,
                new \App\Services\FinancialService($this->database),
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kontrolowany wy');
        $talent->updateRule(AppReferralService::ACTIVITY_TYPE, 1000, 12345, 0, true);
    }

    private function service(): AppReferralService
    {
        $signal = new NullQueueSignal();
        return new AppReferralService(
            $this->database,
            new MailService($this->database),
            new EarningsJobDispatcher(
                $this->database,
                new DurableJobQueue($this->database),
                $signal,
                SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
            ),
            $signal,
        );
    }

    private function register(string $role, string $label, ?string $email = null): int
    {
        $user = (new AuthService($this->database))->register([
            'display_name' => 'Referral ' . $label,
            'email' => $email ?? $this->email($label),
            'phone' => '',
            'password' => 'Referral-test-2026!',
            'role' => $role,
        ]);
        return (int)$user['id'];
    }

    private function email(string $label): string
    {
        return 'referral-' . $label . '-' . bin2hex(random_bytes(6)) . '@example.test';
    }

    private function tokenFromInvitation(int $invitationId): string
    {
        $body = (string)$this->database->cell(
            'SELECT m.body FROM app_referral_invitations i JOIN mail_queue m ON m.id=i.mail_queue_id WHERE i.id=:id',
            ['id' => $invitationId]
        );
        self::assertMatchesRegularExpression('~/app/referral/[A-Za-z0-9_-]{43}~', $body);
        preg_match('~/app/referral/([A-Za-z0-9_-]{43})~', $body, $match);
        return (string)$match[1];
    }
}
