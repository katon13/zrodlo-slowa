<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiProviderInterface;
use App\Contracts\BackgroundJobHandlerInterface;
use App\Core\Database;
use App\Services\AiBudgetService;
use App\Services\AiFoundationService;
use App\Services\ArticleTranslationService;
use App\Services\CacheService;
use App\Services\MainBannerService;
use App\Services\RoleService;
use App\Services\StructuredAuditService;
use App\Services\TranslationAiService;
use App\Services\TranslationPromptBuilder;

final class AiJobHandler implements BackgroundJobHandlerInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly AiProviderInterface $provider,
        private readonly array $languageConfig,
        private readonly ?CacheService $cache = null,
    ) {}

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [
            'ai.provider_test',
            'ai.article_translation_package',
            'ai.main_banner_translation',
        ], true);
    }

    public function handle(array $job): array
    {
        $actorId = (int)($job['actor_user_id'] ?? 0);
        $permission = trim((string)($job['required_permission'] ?? ''));
        $this->authorize($actorId, $permission);
        $payload = json_decode((string)$job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $audit = new StructuredAuditService($this->db);
        $details = [
            'background_job_id' => (int)$job['id'],
            'public_id' => (string)$job['public_id'],
            'job_type' => (string)$job['job_type'],
            'required_permission' => $permission,
            'request_id' => $job['request_id'] ?? null,
            'actor_ip' => $job['actor_ip'] ?? null,
            'attempt' => (int)$job['attempts'],
        ];
        $audit->record($actorId, 'ai.background_job.started', $details);

        try {
            $result = match ((string)$job['job_type']) {
                'ai.provider_test' => $this->providerTest($actorId, $payload),
                'ai.article_translation_package' => $this->articleTranslation($actorId, $payload),
                'ai.main_banner_translation' => $this->bannerTranslation($actorId, $payload),
                default => throw new NonRetryableJobException('Nieobsługiwany typ zadania AI.'),
            };
            $audit->record($actorId, 'ai.background_job.completed', $details + [
                'result_summary' => $result,
            ]);
            return $result;
        } catch (JobRejectedException|NonRetryableJobException $error) {
            $audit->record($actorId, 'ai.background_job.failed', $details + ['error_type' => $error::class], 'failure');
            throw $error;
        } catch (\Throwable $error) {
            $audit->record($actorId, 'ai.background_job.unknown_outcome', $details + ['error_type' => $error::class], 'failure');
            throw new UnknownOutcomeJobException(
                'Nieznany wynik wywołania dostawcy AI; zadanie wymaga ręcznej kontroli i nie będzie ponawiane automatycznie.',
                0,
                $error,
            );
        }
    }

    private function authorize(int $actorId, string $permission): void
    {
        if ($actorId <= 0 || $permission === '') {
            throw new JobRejectedException('Brak aktora lub wymaganego uprawnienia zadania AI.');
        }
        $user = $this->db->one('SELECT id,status FROM users WHERE id=:id LIMIT 1', ['id' => $actorId]);
        if ($user === null || (string)$user['status'] !== 'active') {
            throw new JobRejectedException('Konto aktora AI nie jest aktywne.');
        }
        if (!(new RoleService($this->db))->userHasPermission($actorId, $permission)) {
            throw new JobRejectedException('Aktor utracił wymagane uprawnienie do zadania AI.');
        }
        if (!$this->provider->configured()) {
            throw new NonRetryableJobException('Dostawca AI nie jest skonfigurowany w workerze AI.');
        }
        $settings = (new AiFoundationService($this->db))->settings();
        if (($settings['ai.enabled'] ?? '0') !== '1' || ($settings['ai.jobs.execute_api_enabled'] ?? '0') !== '1') {
            throw new NonRetryableJobException('Wykonywanie zadań AI jest wyłączone w ustawieniach administracyjnych.');
        }
    }

    private function providerTest(int $actorId, array $payload): array
    {
        $model = trim((string)($payload['model'] ?? ''));
        return $this->withCostGuard($actorId, 'openai_connection_test_guard', 100, 1, function () use ($actorId, $model): array {
            return (new AiFoundationService($this->db))->testOpenAiConnection($this->provider, $actorId, $model);
        });
    }

    private function articleTranslation(int $actorId, array $payload): array
    {
        $articleId = (int)($payload['article_id'] ?? 0);
        $article = $this->db->one('SELECT id,title,lead,body,source_language,updated_at FROM articles WHERE id=:id LIMIT 1', ['id' => $articleId]);
        if ($article === null) {
            throw new NonRetryableJobException('Artykuł przypisany do zadania AI nie istnieje.');
        }
        $currentHash = hash('sha256', json_encode($article, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (!hash_equals((string)($payload['source_hash'] ?? ''), $currentHash)) {
            throw new NonRetryableJobException('Treść artykułu zmieniła się od chwili zlecenia; wymagane jest nowe zadanie AI.');
        }
        $service = new TranslationAiService(
            $this->db,
            new AiFoundationService($this->db),
            $this->provider,
            new TranslationPromptBuilder(),
            new ArticleTranslationService($this->db, $this->languageConfig),
        );
        return $service->generateArticleTranslationPackage(
            $articleId,
            array_values(array_map('strval', (array)($payload['target_languages'] ?? []))),
            (string)($payload['instructions'] ?? ''),
            $actorId,
            $this->languageConfig,
        );
    }

    private function bannerTranslation(int $actorId, array $payload): array
    {
        $source = $this->db->one(
            'SELECT t.kicker,t.title,t.lead_text,t.body_text,t.button_label,t.updated_at
             FROM main_banner_translations t
             JOIN main_banners b ON b.id=t.banner_id
             WHERE b.slug=\'home-main\' AND t.language=\'pl\' LIMIT 1'
        );
        $currentHash = hash('sha256', json_encode($source ?: [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (!hash_equals((string)($payload['source_hash'] ?? ''), $currentHash)) {
            throw new NonRetryableJobException('Treść Baneru Głównego zmieniła się od chwili zlecenia.');
        }
        $targets = array_values(array_map('strval', (array)($payload['target_languages'] ?? [])));
        $characters = max(1, mb_strlen(json_encode($source ?: [], JSON_UNESCAPED_UNICODE) ?: '', 'UTF-8'));
        $result = $this->withCostGuard($actorId, 'main_banner_translation_guard', $characters, max(1, count($targets)), function () use ($actorId, $payload, $targets): array {
            return (new MainBannerService($this->db))->translateWithAi(
                $this->provider,
                new AiFoundationService($this->db),
                $targets,
                (string)($payload['instructions'] ?? ''),
                $actorId,
                $this->languageConfig,
            );
        });
        $this->cache?->flushGroup('main_banner_public');
        return $result;
    }

    private function withCostGuard(int $actorId, string $type, int $sourceCharacters, int $targets, callable $operation): array
    {
        $foundation = new AiFoundationService($this->db);
        $guardJobId = $foundation->reserveTranslationBudget(
            max(1, $sourceCharacters),
            max(1, $targets),
            function (Database $db, int $estimate, string $period) use ($actorId, $type): int {
                return $db->insert(
                    'INSERT INTO ai_jobs(
                        public_id,user_id,type,provider,model,status,prompt_code,prompt_version,input_hash,input_json,
                        estimated_cost_minor,budget_period,budget_status,queued_at,started_at,created_at,updated_at
                     ) VALUES(
                        :public_id,:user_id,:type,\'openai\',\'cost-guard\',\'running\',:prompt_code,\'v1\',:input_hash,
                        CAST(:input_json AS JSON),:estimate,:period,\'reserved\',NOW(),NOW(),NOW(),NOW()
                     )',
                    [
                        'public_id' => 'ai_guard_' . bin2hex(random_bytes(12)),
                        'user_id' => $actorId,
                        'type' => $type,
                        'prompt_code' => $type,
                        'input_hash' => hash('sha256', $type . ':' . $actorId . ':' . microtime(true)),
                        'input_json' => json_encode(['purpose' => 'cost_limit_guard'], JSON_THROW_ON_ERROR),
                        'estimate' => $estimate,
                        'period' => $period,
                    ]
                );
            }
        );
        try {
            $result = $operation();
            $this->db->query('UPDATE ai_jobs SET status=\'completed\',completed_at=NOW(),updated_at=NOW() WHERE id=:id', ['id' => $guardJobId]);
            (new AiBudgetService($this->db))->settle($guardJobId);
            return $result;
        } catch (\Throwable $error) {
            $this->db->query(
                'UPDATE ai_jobs SET status=\'error\',error_message=:error,completed_at=NOW(),updated_at=NOW() WHERE id=:id',
                ['error' => mb_substr($error->getMessage(), 0, 1000), 'id' => $guardJobId]
            );
            (new AiBudgetService($this->db))->settle($guardJobId);
            throw $error;
        }
    }
}
