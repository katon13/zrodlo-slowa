<?php
namespace App\Services;

use App\Contracts\AiProviderInterface;
use App\Core\Database;

final class AiFoundationService
{
    public function __construct(private readonly Database $db) {}

    public function settings(): array
    {
        $defaults = [
            'ai.enabled' => '0',
            'ai.default_provider' => 'openai',
            'ai.openai.model' => 'gpt-5.5',
            'ai.translation.model' => 'gpt-5.5',
            'ai.translation.max_chars_per_job' => '60000',
            'ai.translation.premium_model' => 'gpt-5.5',
            'ai.translation.daily_jobs_limit' => '20',
            'ai.translation.monthly_budget_minor' => '5000',
            'ai.translation.estimated_cost_per_1k_chars_minor' => '5',
            'ai.storage.source_of_truth' => 'database',
            'ai.storage.raw_json_policy' => 'audit_only',
            'ai.translation.enabled' => '0',
            'ai.translation.require_editor_review' => '1',
            'ai.translation.default_source_language' => 'pl',
            'ai.translation.default_target_language' => 'en',
            'ai.translation.default_instruction' => 'Tłumacz tekst na wszystkie wskazane języki: angielski, niemiecki, francuski, włoski i hiszpański, a jeśli tekst źródłowy nie jest po polsku, przygotuj także wersję polską. Tłumacz naturalnie, redakcyjnie i wiernie. Nie streszczaj, nie dopisuj faktów, nie poprawiaj autora ideologicznie i nie zmieniaj sensu tekstu. Zachowaj ton autora, rytm wypowiedzi, intencję, nazwy własne, cytaty, odniesienia kulturowe i religijne. Tytuł może być językowo naturalny, ale nie może zmieniać znaczenia. Unikaj dosłowności tam, gdzie niszczy sens, ale nie parafrazuj ponad potrzebę. Zachowuj ostrożność przy pojęciach teologicznych, historycznych, politycznych, społecznych i prawnych. Nie zamieniaj pojęć katolickich, biblijnych ani kulturowych na przypadkowe odpowiedniki. Jeśli termin ma utrwalony odpowiednik w języku docelowym, użyj go. Wynik ma być gotowy do redakcyjnej korekty: poprawny językowo, spokojny, elegancki i zgodny z charakterem ŹRÓDŁA SŁOWA.',
            'ai.jobs.manual_planning_enabled' => '1',
            'ai.jobs.require_admin' => '1',
            'ai.jobs.execute_api_enabled' => '0',
            'ai.openai.last_test_status' => 'never',
            'ai.openai.last_test_at' => '',
            'ai.openai.last_test_error' => '',
        ];
        try {
            $params = [];
            $placeholders = [];
            foreach (array_keys($defaults) as $index => $name) {
                $parameter = 'setting_' . $index;
                $placeholders[] = ':' . $parameter;
                $params[$parameter] = $name;
            }
            $rows = $this->db->all(
                'SELECT name,value FROM settings WHERE name IN (' . implode(',', $placeholders) . ')',
                $params
            );
            foreach ($rows as $row) {
                $defaults[(string)$row['name']] = (string)$row['value'];
            }
        } catch (\Throwable) {}
        return $defaults;
    }

    public function updateDefaultTranslationInstruction(string $instruction): void
    {
        $this->saveSetting(
            'ai.translation.default_instruction',
            mb_substr(trim($instruction), 0, 5000)
        );
    }

    public function updateSettings(array $input): void
    {
        $allowed = [
            'ai.enabled' => ['type' => 'bool'],
            'ai.default_provider' => ['type' => 'enum', 'values' => ['openai']],
            'ai.openai.model' => ['type' => 'model'],
            'ai.translation.model' => ['type' => 'model'],
            'ai.translation.max_chars_per_job' => ['type' => 'int_positive', 'min' => 1, 'max' => 200000],
            'ai.translation.premium_model' => ['type' => 'model'],
            'ai.translation.daily_jobs_limit' => ['type' => 'int_positive', 'min' => 1, 'max' => 1000],
            'ai.translation.monthly_budget_minor' => ['type' => 'int_positive', 'min' => 1, 'max' => 1000000],
            'ai.translation.estimated_cost_per_1k_chars_minor' => ['type' => 'int_positive', 'min' => 1, 'max' => 10000],
            'ai.storage.source_of_truth' => ['type' => 'enum', 'values' => ['database']],
            'ai.storage.raw_json_policy' => ['type' => 'enum', 'values' => ['audit_only', 'disabled']],
            'ai.translation.enabled' => ['type' => 'bool'],
            'ai.translation.require_editor_review' => ['type' => 'bool'],
            'ai.translation.default_source_language' => ['type' => 'language'],
            'ai.translation.default_target_language' => ['type' => 'language'],
            'ai.translation.default_instruction' => ['type' => 'long_text'],
            'ai.jobs.manual_planning_enabled' => ['type' => 'bool'],
            'ai.jobs.require_admin' => ['type' => 'bool'],
            'ai.jobs.execute_api_enabled' => ['type' => 'bool'],
        ];
        foreach ($allowed as $name => $rule) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $value = $this->normalize($input[$name] ?? null, $rule, $name);
            $this->saveSetting($name, $value);
        }
    }

    public function summary(): array
    {
        $empty = [
            'jobs_count' => 0,
            'planned_jobs_count' => 0,
            'translations_count' => 0,
            'draft_translations_count' => 0,
            'prompts_count' => 0,
            'events_count' => 0,
            'translation_jobs_today' => 0,
            'translation_jobs_month' => 0,
            'openai_connection_tests' => 0,
        ];
        try {
            $jobs = $this->db->one('SELECT COUNT(*) AS cnt, SUM(CASE WHEN status=\'planned\' THEN 1 ELSE 0 END) AS planned_cnt FROM ai_jobs') ?: [];
            $translations = $this->db->one('SELECT COUNT(*) AS cnt, SUM(CASE WHEN status IN (\'draft\',\'ai_draft\') THEN 1 ELSE 0 END) AS draft_cnt FROM article_translations') ?: [];
            $prompts = $this->db->one('SELECT COUNT(*) AS cnt FROM ai_prompt_templates WHERE is_active=1') ?: [];
            $events = ['cnt' => 0];
            $translationJobsToday = ['cnt' => 0];
            $translationJobsMonth = ['cnt' => 0];
            $openaiTests = ['cnt' => 0];
            try {
                $events = $this->db->one('SELECT COUNT(*) AS cnt FROM ai_job_events') ?: ['cnt' => 0];
            } catch (\Throwable) {}
            try {
                $translationJobsToday = $this->db->one(
                    'SELECT COUNT(*) AS cnt FROM ai_jobs
                     WHERE type=\'article_translation\' AND DATE(created_at)=CURRENT_DATE'
                ) ?: ['cnt' => 0];
                $translationJobsMonth = $this->db->one(
                    'SELECT COUNT(*) AS cnt FROM ai_jobs
                     WHERE type=\'article_translation\' AND created_at >= '
                    . $this->db->currentMonthStart()
                ) ?: ['cnt' => 0];
                $openaiTests = $this->db->one('SELECT COUNT(*) AS cnt FROM ai_jobs WHERE type=\'openai_connection_test\'') ?: ['cnt' => 0];
            } catch (\Throwable) {}
            return [
                'jobs_count' => (int)($jobs['cnt'] ?? 0),
                'planned_jobs_count' => (int)($jobs['planned_cnt'] ?? 0),
                'translations_count' => (int)($translations['cnt'] ?? 0),
                'draft_translations_count' => (int)($translations['draft_cnt'] ?? 0),
                'prompts_count' => (int)($prompts['cnt'] ?? 0),
                'events_count' => (int)($events['cnt'] ?? 0),
                'translation_jobs_today' => (int)($translationJobsToday['cnt'] ?? 0),
                'translation_jobs_month' => (int)($translationJobsMonth['cnt'] ?? 0),
                'openai_connection_tests' => (int)($openaiTests['cnt'] ?? 0),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    public function recentJobs(int $limit = 30): array
    {
        try {
            return $this->db->all('SELECT j.*, a.title AS article_title, u.display_name, u.email FROM ai_jobs j LEFT JOIN articles a ON a.id=j.article_id LEFT JOIN users u ON u.id=j.user_id ORDER BY j.created_at DESC, j.id DESC LIMIT ' . (int)$limit);
        } catch (\Throwable) {
            return [];
        }
    }

    public function recentTranslations(int $limit = 30): array
    {
        try {
            return $this->db->all('SELECT t.*, a.title AS article_title, u.display_name AS creator_name FROM article_translations t LEFT JOIN articles a ON a.id=t.article_id LEFT JOIN users u ON u.id=t.created_by ORDER BY t.created_at DESC, t.id DESC LIMIT ' . (int)$limit);
        } catch (\Throwable) {
            return [];
        }
    }

    public function recentEvents(int $limit = 30): array
    {
        try {
            return $this->db->all('SELECT e.*, j.public_id, j.type, a.title AS article_title, u.display_name AS actor_name FROM ai_job_events e LEFT JOIN ai_jobs j ON j.id=e.ai_job_id LEFT JOIN articles a ON a.id=j.article_id LEFT JOIN users u ON u.id=e.actor_user_id ORDER BY e.created_at DESC, e.id DESC LIMIT ' . (int)$limit);
        } catch (\Throwable) {
            return [];
        }
    }

    public function promptTemplates(): array
    {
        try {
            return $this->db->all('SELECT * FROM ai_prompt_templates ORDER BY task_type, code');
        } catch (\Throwable) {
            return [];
        }
    }

    public function activePromptTemplates(): array
    {
        try {
            return $this->db->all('SELECT * FROM ai_prompt_templates WHERE is_active=1 ORDER BY task_type, code');
        } catch (\Throwable) {
            return [];
        }
    }

    public function articlesForPlanning(int $limit = 80): array
    {
        try {
            return $this->db->all('SELECT a.id, a.title, a.status, a.language, a.updated_at, a.created_at, u.display_name AS author_name, u.email AS author_email FROM articles a LEFT JOIN users u ON u.id=a.author_id ORDER BY COALESCE(a.updated_at,a.created_at) DESC, a.id DESC LIMIT ' . (int)$limit);
        } catch (\Throwable) {
            try {
                return $this->db->all('SELECT id, title, status, created_at FROM articles ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit);
            } catch (\Throwable) {
                return [];
            }
        }
    }

    public function createPlannedArticleJob(array $input, int $adminId): int
    {
        $settings = $this->settings();
        if (($settings['ai.jobs.manual_planning_enabled'] ?? '1') !== '1') {
            throw new \RuntimeException('Manualne planowanie zadań AI jest wyłączone.');
        }
        $articleId = (int)($input['article_id'] ?? 0);
        if ($articleId <= 0) {
            throw new \InvalidArgumentException('Wybierz tekst do zaplanowania pracy AI.');
        }

        $promptCode = trim((string)($input['prompt_code'] ?? 'article_ai_review_plan_v1'));
        if ($promptCode === '' || !preg_match('/^[a-zA-Z0-9_.:-]+$/', $promptCode)) {
            throw new \InvalidArgumentException('Nieprawidłowy kod promptu.');
        }

        $taskType = trim((string)($input['task_type'] ?? 'editorial_plan'));
        $allowedTypes = ['editorial_plan', 'editorial_assist_plan', 'translation_plan'];
        if (!in_array($taskType, $allowedTypes, true)) {
            throw new \InvalidArgumentException('Nieprawidłowy typ planu AI.');
        }

        $targetLanguage = $this->normalizeLanguage($input['target_language'] ?? ($settings['ai.translation.default_target_language'] ?? 'en'), 'target_language');
        $sourceLanguage = $this->normalizeLanguage($input['source_language'] ?? ($settings['ai.translation.default_source_language'] ?? 'pl'), 'source_language');
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 1000);

        return $this->db->transaction(function (Database $db) use ($articleId, $adminId, $promptCode, $taskType, $sourceLanguage, $targetLanguage, $note, $settings): int {
            $article = $db->one('SELECT * FROM articles WHERE id=:id LIMIT 1', ['id' => $articleId]);
            if (!$article) {
                throw new \RuntimeException('Nie znaleziono tekstu.');
            }

            $prompt = $db->one('SELECT * FROM ai_prompt_templates WHERE code=:code AND is_active=1 LIMIT 1', ['code' => $promptCode]);
            if (!$prompt) {
                throw new \RuntimeException('Nie znaleziono aktywnego promptu AI.');
            }

            $snapshot = $this->articleSnapshot($article);
            $inputJson = [
                'stage' => 'patch5_stage6',
                'mode' => 'plan_only_no_api_call',
                'source_of_truth' => 'database',
                'raw_json_policy' => $settings['ai.storage.raw_json_policy'] ?? 'audit_only',
                'article_snapshot' => $snapshot,
                'language' => [
                    'source' => $sourceLanguage,
                    'target' => $targetLanguage,
                ],
                'editor_note' => $note,
                'guardrails' => [
                    'do_not_translate_now' => true,
                    'do_not_publish_automatically' => true,
                    'human_review_required' => true,
                    'do_not_replace_article_body' => true,
                ],
            ];
            $hash = hash('sha256', json_encode($inputJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $publicId = 'ai_' . bin2hex(random_bytes(16));

            $jobId = $db->insert('INSERT INTO ai_jobs(public_id,user_id,article_id,type,provider,model,status,prompt_code,prompt_version,input_hash,input_json,queued_at,created_at,updated_at) VALUES(:public_id,:user_id,:article_id,:type,:provider,:model,\'planned\',:prompt_code,:prompt_version,:input_hash,:input_json,NOW(),NOW(),NOW())', [
                'public_id' => $publicId,
                'user_id' => $adminId,
                'article_id' => $articleId,
                'type' => $taskType,
                'provider' => (string)($prompt['provider'] ?? 'openai'),
                'model' => (string)($prompt['model'] ?: ($settings['ai.openai.model'] ?? 'gpt-5.5')),
                'prompt_code' => $promptCode,
                'prompt_version' => (string)($prompt['version'] ?? 'v1'),
                'input_hash' => $hash,
                'input_json' => json_encode($inputJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $this->recordJobEvent($jobId, 'planned', $adminId, null, 'planned', 'Zaplanowano zadanie AI bez wywołania OpenAI.', [
                'prompt_code' => $promptCode,
                'task_type' => $taskType,
                'article_id' => $articleId,
                'target_language' => $targetLanguage,
            ]);

            return $jobId;
        });
    }


    /**
     * @return array<string, mixed>
     */
    public function testOpenAiConnection(AiProviderInterface $client, int $adminId, ?string $model = null): array
    {
        $settings = $this->settings();
        $model = trim((string)($model ?: ($settings['ai.openai.model'] ?? 'gpt-5.5')));
        if ($model === '') {
            $model = 'gpt-5.5';
        }

        $publicId = 'ai_test_' . bin2hex(random_bytes(12));
        $jobId = 0;
        try {
            $jobId = $this->db->insert(
                'INSERT INTO ai_jobs(public_id,user_id,type,provider,model,status,prompt_code,prompt_version,input_hash,input_json,queued_at,started_at,created_at,updated_at)
                 VALUES(:public_id,:user_id,\'openai_connection_test\',\'openai\',:model,\'running\',\'openai_connection_test\',\'v1\',:input_hash,CAST(:input_json AS JSON),NOW(),NOW(),NOW(),NOW())',
                [
                    'public_id' => $publicId,
                    'user_id' => $adminId,
                    'model' => $model,
                    'input_hash' => hash('sha256', $publicId . $model),
                    'input_json' => json_encode([
                        'stage' => 'etap10_openai_admin_extension',
                        'purpose' => 'openai_connection_test',
                        'guardrails' => [
                            'do_not_translate' => true,
                            'do_not_publish' => true,
                            'do_not_edit_public_json_phrases' => true,
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            $this->recordJobEvent($jobId, 'openai_connection_test_started', $adminId, null, 'running', 'Rozpoczęto test połączenia OpenAI.', ['model' => $model]);

            $result = $client->testConnection($model);
            $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
            $this->db->query(
                'UPDATE ai_jobs SET status=\'completed\', output_json=CAST(:output_json AS JSON), tokens_input=:tokens_input, tokens_output=:tokens_output, completed_at=NOW(), updated_at=NOW() WHERE id=:id',
                [
                    'id' => $jobId,
                    'output_json' => json_encode($result['raw'] ?? ['status' => 'success'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'tokens_input' => (int)($usage['input_tokens'] ?? 0),
                    'tokens_output' => (int)($usage['output_tokens'] ?? 0),
                ]
            );
            $this->recordJobEvent($jobId, 'openai_connection_test_completed', $adminId, 'running', 'completed', 'Test połączenia OpenAI zakończony powodzeniem.', ['model' => (string)($result['model'] ?? $model)]);
            $this->saveSetting('ai.openai.last_test_status', 'success');
            $this->saveSetting('ai.openai.last_test_at', date('Y-m-d H:i:s'));
            $this->saveSetting('ai.openai.last_test_error', '');

            return [
                'job_id' => $jobId,
                'status' => 'success',
                'model' => (string)($result['model'] ?? $model),
            ];
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            if ($jobId > 0) {
                try {
                    $this->db->query('UPDATE ai_jobs SET status=\'error\', error_message=:message, completed_at=NOW(), updated_at=NOW() WHERE id=:id', [
                        'id' => $jobId,
                        'message' => $message,
                    ]);
                    $this->recordJobEvent($jobId, 'openai_connection_test_error', $adminId, 'running', 'error', mb_substr($message, 0, 250), ['model' => $model]);
                } catch (\Throwable) {}
            }
            $this->saveSetting('ai.openai.last_test_status', 'error');
            $this->saveSetting('ai.openai.last_test_at', date('Y-m-d H:i:s'));
            $this->saveSetting('ai.openai.last_test_error', $message);
            throw $e;
        }
    }

    public function assertTranslationLimits(): void
    {
        $settings = $this->settings();
        $dailyLimit = (int)($settings['ai.translation.daily_jobs_limit'] ?? 20);
        if ($dailyLimit > 0) {
            try {
                $today = (int)($this->db->cell(
                    'SELECT COUNT(*) FROM ai_jobs
                     WHERE type=\'article_translation\' AND DATE(created_at)=CURRENT_DATE'
                ) ?: 0);
                if ($today >= $dailyLimit) {
                    throw new \RuntimeException('Przekroczono dzienny limit zleceń AI dla tłumaczeń.');
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable) {}
        }
    }

    public function reserveTranslationBudget(int $sourceCharacters, int $targetLanguageCount, callable $operation): mixed
    {
        $settings = $this->settings();
        $budget = (int)($settings['ai.translation.monthly_budget_minor'] ?? 5000);
        $rate = (int)($settings['ai.translation.estimated_cost_per_1k_chars_minor'] ?? 5);
        $service = new AiBudgetService($this->db);
        $estimate = $service->estimate($sourceCharacters, $targetLanguageCount, $rate);
        return $service->reserveAndRun($estimate, $budget, $operation);
    }

    public function settleTranslationBudget(int $jobId): void
    {
        (new AiBudgetService($this->db))->settle($jobId);
    }

    private function saveSetting(string $name, string $value): void
    {
        try {
            $sql = $this->db->isPostgres()
                ? 'INSERT INTO settings(name,value,updated_at) VALUES(:name,:value,NOW())
                   ON CONFLICT (name) DO UPDATE
                   SET value=EXCLUDED.value,updated_at=NOW()'
                : 'INSERT INTO settings(name,value,updated_at) VALUES(:name,:value,NOW())
                   ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=NOW()';
            $this->db->query($sql, ['name' => $name, 'value' => $value]);
        } catch (\Throwable) {}
    }

    private function recordJobEvent(int $jobId, string $eventType, ?int $actorUserId, ?string $from, string $to, string $message, array $payload = []): void
    {
        try {
            $this->db->query('INSERT INTO ai_job_events(ai_job_id,event_type,actor_user_id,status_from,status_to,message,payload_json,created_at) VALUES(:job,:event,:actor,:from_status,:to_status,:message,:payload,NOW())', [
                'job' => $jobId,
                'event' => $eventType,
                'actor' => $actorUserId,
                'from_status' => $from,
                'to_status' => $to,
                'message' => mb_substr($message, 0, 255),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {}
    }

    private function articleSnapshot(array $article): array
    {
        $body = (string)($article['body'] ?? $article['content'] ?? $article['text'] ?? '');
        return [
            'id' => (int)($article['id'] ?? 0),
            'author_id' => isset($article['author_id']) ? (int)$article['author_id'] : null,
            'title' => (string)($article['title'] ?? ''),
            'lead' => (string)($article['lead'] ?? $article['excerpt'] ?? ''),
            'body_excerpt' => mb_substr(strip_tags($body), 0, 4000),
            'body_length' => mb_strlen(strip_tags($body)),
            'language' => (string)($article['language'] ?? 'pl'),
            'status' => (string)($article['status'] ?? ''),
            'is_premium' => (int)($article['is_premium'] ?? 0),
            'updated_at' => (string)($article['updated_at'] ?? ''),
        ];
    }

    private function normalize(mixed $raw, array $rule, string $name): string
    {
        return match ($rule['type']) {
            'bool' => in_array((string)$raw, ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            'bool_forced_off' => '0',
            'int_positive' => $this->normalizePositiveInt($raw, $rule, $name),
            'enum' => in_array((string)$raw, $rule['values'], true) ? (string)$raw : throw new \InvalidArgumentException('Nieprawidłowa wartość ustawienia: ' . $name),
            'long_text' => mb_substr(trim((string)$raw), 0, 5000),
            'language' => $this->normalizeLanguage($raw, $name),
            'model' => $this->normalizeModel($raw, $name),
            default => throw new \InvalidArgumentException('Nieznany typ ustawienia: ' . $name),
        };
    }

    private function normalizePositiveInt(mixed $raw, array $rule, string $name): string
    {
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException('Ustawienie ' . $name . ' musi być liczbą.');
        }
        $value = (int)$raw;
        $min = (int)($rule['min'] ?? 1);
        $max = (int)($rule['max'] ?? 200000);
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException('Ustawienie ' . $name . ' jest poza dozwolonym zakresem.');
        }
        return (string)$value;
    }

    private function normalizeLanguage(mixed $raw, string $name): string
    {
        $value = strtolower(trim((string)$raw));
        if (!preg_match('/^[a-z]{2,8}(-[a-z0-9]{2,8})?$/', $value)) {
            throw new \InvalidArgumentException('Nieprawidłowy kod języka: ' . $name);
        }
        return $value;
    }

    private function normalizeModel(mixed $raw, string $name): string
    {
        $value = trim((string)$raw);
        if ($value === '' || strlen($value) > 128 || !preg_match('/^[a-zA-Z0-9._:-]+$/', $value)) {
            throw new \InvalidArgumentException('Nieprawidłowy model AI: ' . $name);
        }
        return $value;
    }
}
