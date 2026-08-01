<?php
namespace App\Services;

use App\Contracts\AiProviderInterface;
use App\Core\Database;
use App\Models\ArticleTranslation;

final class TranslationAiService
{
    public function __construct(
        private readonly Database $db,
        private readonly AiFoundationService $aiFoundation,
        private readonly AiProviderInterface $client,
        private readonly TranslationPromptBuilder $promptBuilder,
        private readonly ArticleTranslationService $translations
    ) {}

    /**
     * @param array<string, mixed> $languageConfig
     * @return array<string, mixed>
     */
    public function generateArticleTranslationPackage(int $articleId, array $targetLanguages, string $instructions, int $userId, array $languageConfig = []): array
    {
        $settings = $this->aiFoundation->settings();
        if (($settings['ai.enabled'] ?? '0') !== '1') {
            throw new \RuntimeException('AI jest wyłączone w ustawieniach administracyjnych.');
        }
        if (($settings['ai.translation.enabled'] ?? '0') !== '1') {
            throw new \RuntimeException('Tłumaczenia AI są wyłączone w ustawieniach administracyjnych.');
        }
        if (($settings['ai.jobs.execute_api_enabled'] ?? '0') !== '1') {
            throw new \RuntimeException('Wywołania API AI są wyłączone. Włącz je świadomie w panelu AI przed generowaniem.');
        }
        if (!$this->client->configured()) {
            throw new \RuntimeException('Brak skonfigurowanego klucza OpenAI.');
        }
        $this->aiFoundation->assertTranslationLimits();

        $article = $this->db->one('SELECT * FROM articles WHERE id=:id LIMIT 1', ['id' => $articleId]);
        if (!$article) {
            throw new \RuntimeException('Nie znaleziono artykułu.');
        }

        $sourceLanguage = $this->normalizeConfiguredLanguage((string)($article['source_language'] ?? ''), $languageConfig);
        $targetLanguages = $this->normalizeTargetLanguages($targetLanguages, $sourceLanguage, $languageConfig);
        if ($targetLanguages === []) {
            throw new \RuntimeException('Brak języków docelowych do tłumaczenia.');
        }

        $bodyChars = mb_strlen((string)($article['body'] ?? ''), 'UTF-8');
        $maxChars = (int)($settings['ai.translation.max_chars_per_job'] ?? 60000);
        if ($maxChars > 0 && $bodyChars > $maxChars) {
            throw new \RuntimeException('Tekst przekracza limit długości dla jednego zlecenia AI.');
        }

        $model = (string)($settings['ai.translation.model'] ?? ($settings['ai.openai.model'] ?? 'gpt-5.5'));
        $systemPrompt = $this->promptBuilder->systemPrompt();
        $packagePayload = $this->promptBuilder->packagePayload($article, $sourceLanguage, $targetLanguages, $instructions);
        $userPrompt = $this->promptBuilder->userPromptPackage($article, $sourceLanguage, $targetLanguages, $instructions);
        $schema = $this->promptBuilder->outputSchemaPackage($targetLanguages);

        $jobId = 0;
        try {
            $jobId = $this->createPackageJob(
                $articleId,
                $userId,
                $sourceLanguage,
                $targetLanguages,
                $model,
                $systemPrompt,
                $userPrompt,
                $schema,
                $packagePayload,
                $bodyChars
            );
            $result = $this->client->structuredJson($systemPrompt, $userPrompt, $schema, $model);
            $translations = $this->validatePackageData($result['data'] ?? [], $articleId, $sourceLanguage, $targetLanguages);
            $savedLanguages = $this->translations->saveAiTranslationPackage(
                $articleId,
                $sourceLanguage,
                $translations,
                $targetLanguages,
                $instructions,
                $userId,
                $jobId > 0 ? $jobId : null,
                (string)($result['model'] ?? $model)
            );

            if ($jobId > 0) {
                $this->completePackageJob($jobId, $articleId, $sourceLanguage, $targetLanguages, $result, $savedLanguages);
            }

            return [
                'ai_job_id' => $jobId,
                'source_language' => $sourceLanguage,
                'target_languages' => $targetLanguages,
                'saved_languages' => $savedLanguages,
                'model' => (string)($result['model'] ?? $model),
            ];
        } catch (\Throwable $e) {
            if ($jobId > 0) {
                $this->failJob($jobId, $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $languageConfig
     */
    private function normalizeConfiguredLanguage(string $language, array $languageConfig): string
    {
        $language = strtolower(trim($language));
        $enabled = $this->publicEnabledLanguages($languageConfig);
        if ($language === '' || !in_array($language, $enabled, true)) {
            return 'pl';
        }
        return $language;
    }

    /**
     * @param array<string, mixed> $languageConfig
     * @return array<int, string>
     */
    private function publicEnabledLanguages(array $languageConfig): array
    {
        $enabled = $languageConfig['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];
        if (!is_array($enabled)) {
            $enabled = ['pl', 'en', 'de', 'fr', 'it', 'es'];
        }

        $enabled = array_values(array_unique(array_filter(
            array_map(static fn($lang): string => strtolower(trim((string)$lang)), $enabled),
            static fn(string $lang): bool => $lang !== ''
        )));

        return $enabled !== [] ? $enabled : ['pl', 'en', 'de', 'fr', 'it', 'es'];
    }

    /**
     * ETAP 12: języki docelowe zawsze wynikają z public_enabled minus source_language.
     * Jeśli source_language nie jest PL, język PL zostaje na liście, bo jest językiem publicznym.
     *
     * @param array<string, mixed> $languageConfig
     * @return array<int, string>
     */
    private function targetLanguages(string $sourceLanguage, array $languageConfig): array
    {
        $enabled = $this->publicEnabledLanguages($languageConfig);
        return array_values(array_filter(
            $enabled,
            static fn(string $lang): bool => $lang !== $sourceLanguage
        ));
    }

    /**
     * ETAP 15: TranslationAiService pracuje na modelu article_id + target_languages[].
     * Controller ustala listę docelową, a serwis tylko ją normalizuje i pilnuje kontraktu.
     *
     * @param array<int, string> $targetLanguages
     * @param array<string, mixed> $languageConfig
     * @return array<int, string>
     */
    private function normalizeTargetLanguages(array $targetLanguages, string $sourceLanguage, array $languageConfig): array
    {
        $allowed = $this->targetLanguages($sourceLanguage, $languageConfig);
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn($language): string => strtolower(trim((string)$language)), $targetLanguages),
            static fn(string $language): bool => $language !== ''
        )));

        foreach ($normalized as $language) {
            if (!in_array($language, $allowed, true)) {
                throw new \RuntimeException('Niedozwolony język docelowy dla pakietu AI: ' . strtoupper($language));
            }
        }

        return $normalized;
    }

    /**
     * ETAP 19: walidacja pakietu AI przed zapisem.
     * Jeśli pakiet jest błędny, nie zapisujemy częściowo żadnego tłumaczenia.
     *
     * @param mixed $data
     * @param array<int, string> $targetLanguages
     * @return array<string, array<string, string>>
     */
    private function validatePackageData(mixed $data, int $articleId, string $sourceLanguage, array $targetLanguages): array
    {
        if (!is_array($data)) {
            throw new \RuntimeException('Wynik AI nie jest poprawnym obiektem JSON.');
        }

        if (!array_key_exists('article_id', $data) || (int)$data['article_id'] !== $articleId) {
            throw new \RuntimeException('Wynik AI ma niezgodne article_id.');
        }

        if (!array_key_exists('translations', $data) || !is_array($data['translations'])) {
            throw new \RuntimeException('Wynik AI nie zawiera poprawnego obiektu translations.');
        }

        $allowed = array_values(array_unique(array_map(
            static fn(string $language): string => strtolower(trim($language)),
            $targetLanguages
        )));
        if ($allowed === []) {
            throw new \RuntimeException('Brak dozwolonych języków docelowych do walidacji.');
        }

        $normalizedTranslations = [];
        foreach ($data['translations'] as $rawLanguage => $translation) {
            $language = strtolower(trim((string)$rawLanguage));
            if ($language === '') {
                throw new \RuntimeException('Wynik AI zawiera pusty kod języka.');
            }
            if ($language === $sourceLanguage) {
                throw new \RuntimeException('Wynik AI zawiera język źródłowy jako docelowy: ' . strtoupper($language));
            }
            if (!in_array($language, $allowed, true)) {
                throw new \RuntimeException('Wynik AI zawiera niedozwolony język: ' . strtoupper($language));
            }
            if (array_key_exists($language, $normalizedTranslations)) {
                throw new \RuntimeException('Wynik AI zawiera zdublowany język: ' . strtoupper($language));
            }
            if (!is_array($translation)) {
                throw new \RuntimeException('Wynik AI ma niepoprawny obiekt tłumaczenia dla: ' . strtoupper($language));
            }
            $normalizedTranslations[$language] = $translation;
        }

        $missing = array_values(array_diff($allowed, array_keys($normalizedTranslations)));
        if ($missing !== []) {
            throw new \RuntimeException('Wynik AI nie zawiera tłumaczeń dla: ' . strtoupper(implode(', ', $missing)));
        }

        $out = [];
        foreach ($allowed as $language) {
            $item = $normalizedTranslations[$language];
            foreach (['title', 'lead', 'body'] as $field) {
                if (!array_key_exists($field, $item)) {
                    throw new \RuntimeException('Wynik AI nie zawiera pola ' . $field . ' dla: ' . strtoupper($language));
                }
                if (is_array($item[$field]) || is_object($item[$field])) {
                    throw new \RuntimeException('Pole ' . $field . ' ma niepoprawny typ dla: ' . strtoupper($language));
                }
            }

            $title = trim((string)$item['title']);
            $lead = trim((string)$item['lead']);
            $body = trim((string)$item['body']);
            if ($title === '' || $body === '') {
                throw new \RuntimeException('Wynik AI ma pusty tytuł albo treść dla: ' . strtoupper($language));
            }

            $out[$language] = [
                'title' => $title,
                'lead' => $lead,
                'body' => $body,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, string> $targetLanguages
     * @param array<string, mixed> $schema
     */
    private function createPackageJob(
        int $articleId,
        int $userId,
        string $sourceLanguage,
        array $targetLanguages,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        array $packagePayload,
        int $sourceCharacters
    ): int
    {
        $inputJson = [
            'task' => 'article_translation_package_for_one_article_v1',
            'task_name' => 'article_translation_package_for_one_article_v1',
            'article_id' => $articleId,
            'source_language' => $sourceLanguage,
            'target_languages' => array_values($targetLanguages),
            'created_by' => $userId,
            'audit_only' => true,
            'request_payload' => $packagePayload,
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
            'output_schema' => $schema,
            'audit_fields' => [
                'article_id' => $articleId,
                'task_name' => 'article_translation_package_for_one_article_v1',
                'source_language' => $sourceLanguage,
                'target_languages' => array_values($targetLanguages),
                'status' => 'running',
                'request' => 'input_json.request_payload',
                'response' => 'output_json.response_payload',
                'error' => 'error_message',
                'created_by' => $userId,
                'created_at' => 'ai_jobs.created_at',
            ],
            'guardrails' => [
                'do_not_publish' => true,
                'do_not_change_article_status' => true,
                'do_not_change_price_premium_or_access' => true,
                'one_article_one_request' => true,
                'one_json_translations_response' => true,
                'no_per_language_requests' => true,
                'ai_jobs_audit_only' => true,
            ],
        ];

        $publicId = 'ai_' . bin2hex(random_bytes(16));
        $hash = hash('sha256', json_encode($inputJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->aiFoundation->reserveTranslationBudget(
            $sourceCharacters,
            count($targetLanguages),
            function (Database $db, int $estimate, string $period) use (
                $publicId,
                $userId,
                $articleId,
                $model,
                $hash,
                $inputJson
            ): int {
                $jobId = $db->insert(
                    'INSERT INTO ai_jobs(
                        public_id,user_id,article_id,type,provider,model,status,prompt_code,prompt_version,
                        input_hash,input_json,estimated_cost_minor,budget_period,budget_status,
                        queued_at,started_at,created_at,updated_at
                     ) VALUES(
                        :public_id,:user_id,:article_id,\'article_translation\',\'openai\',:model,\'running\',
                        \'article_translation_package_for_one_article_v1\',\'v1\',:input_hash,CAST(:input_json AS JSON),
                        :estimate,:period,\'reserved\',NOW(),NOW(),NOW(),NOW()
                     )',
                    [
                        'public_id' => $publicId,
                        'user_id' => $userId,
                        'article_id' => $articleId,
                        'model' => $model,
                        'input_hash' => $hash,
                        'input_json' => json_encode($inputJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'estimate' => $estimate,
                        'period' => $period,
                    ]
                );
                $this->event($jobId, $userId, 'article_translation_package_started', null, 'running', 'Rozpoczęto pakietowe tłumaczenie AI.');
                return $jobId;
            }
        );
    }

    /**
     * @param array<string, mixed> $result
     * @param array<int, string> $savedLanguages
     */
    private function completePackageJob(int $jobId, int $articleId, string $sourceLanguage, array $targetLanguages, array $result, array $savedLanguages): void
    {
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $raw = $result['raw'] ?? [];
        $outputJson = [
            'task' => 'article_translation_package_for_one_article_v1',
            'task_name' => 'article_translation_package_for_one_article_v1',
            'article_id' => $articleId,
            'source_language' => $sourceLanguage,
            'target_languages' => array_values($targetLanguages),
            'status' => 'completed',
            'response_payload' => $raw,
            'saved_languages' => $savedLanguages,
            'audit_only' => true,
        ];
        $this->db->query(
            'UPDATE ai_jobs
             SET status=\'completed\',
                 output_json=CAST(:output_json AS JSON),
                 tokens_input=:tokens_input,
                 tokens_output=:tokens_output,
                 completed_at=NOW(),
                 updated_at=NOW()
             WHERE id=:id',
            [
                'id' => $jobId,
                'output_json' => json_encode($outputJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'tokens_input' => (int)($usage['input_tokens'] ?? 0),
                'tokens_output' => (int)($usage['output_tokens'] ?? 0),
            ]
        );
        $this->aiFoundation->settleTranslationBudget($jobId);
        $this->event($jobId, null, 'article_translation_package_completed', 'running', 'completed', 'Zapisano pakiet tłumaczeń do article_translations.');
    }

    private function failJob(int $jobId, string $message): void
    {
        $this->db->query(
            'UPDATE ai_jobs SET status=\'error\', error_message=:message, completed_at=NOW(), updated_at=NOW() WHERE id=:id',
            ['id' => $jobId, 'message' => mb_substr($message, 0, 2000)]
        );
        // Wywołanie API mogło zostać rozliczone także wtedy, gdy wynik był błędny,
        // dlatego konserwatywnie zużywamy wcześniejszą estymację zamiast ją zwalniać.
        $this->aiFoundation->settleTranslationBudget($jobId);
        $this->event($jobId, null, 'article_translation_error', 'running', 'error', mb_substr($message, 0, 250));
    }

    private function event(int $jobId, ?int $actorUserId, string $type, ?string $from, ?string $to, string $message): void
    {
        try {
            $this->db->query(
                'INSERT INTO ai_job_events(ai_job_id,event_type,actor_user_id,status_from,status_to,message,created_at)
                 VALUES(:job,:type,:actor,:from_status,:to_status,:message,NOW())',
                [
                    'job' => $jobId,
                    'type' => $type,
                    'actor' => $actorUserId,
                    'from_status' => $from,
                    'to_status' => $to,
                    'message' => $message,
                ]
            );
        } catch (\Throwable) {}
    }

}
