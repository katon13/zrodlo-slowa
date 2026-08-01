<?php
namespace App\Services;

final class TranslationPromptBuilder
{
    public function systemPrompt(): string
    {
        return implode("\n", [
            'Jesteś tłumaczem redakcyjnym serwisu ŹRÓDŁO SŁOWA.',
            'Tłumaczysz tekst według dyspozycji redakcji.',
            'Nie dopisujesz nowych informacji.',
            'Nie zmieniasz sensu.',
            'Nie skracasz tekstu, jeśli redakcja tego nie poleci.',
            'Nie publikujesz tekstu.',
            'Zwracasz wyłącznie dane strukturalne zgodne ze schematem JSON.',
        ]);
    }

    /**
     * @param array<string, mixed> $article
     */
    public function userPrompt(array $article, string $targetLanguage, string $instructions): string
    {
        $payload = [
            'source_language' => 'pl',
            'target_language' => $targetLanguage,
            'editor_translation_instructions' => $instructions,
            'article' => [
                'title' => (string)($article['title'] ?? ''),
                'lead' => (string)($article['lead'] ?? ''),
                'body' => (string)($article['body'] ?? ''),
                'seo_title' => (string)($article['seo_title'] ?? ($article['title'] ?? '')),
                'seo_description' => (string)($article['seo_description'] ?? ($article['lead'] ?? '')),
                'seo_keywords' => (string)($article['seo_keywords'] ?? ''),
            ],
            'output_rules' => [
                'return_json_only' => true,
                'keep_meaning' => true,
                'do_not_publish' => true,
                'human_review_required' => true,
                'slug_should_be_ascii_lowercase_if_possible' => true,
            ],
        ];

        return 'Przygotuj wersję roboczą tłumaczenia artykułu. Dane wejściowe JSON:' . "\n" .
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'title',
                'lead',
                'body',
                'seo_title',
                'seo_description',
                'seo_keywords',
                'slug',
                'translator_notes',
                'terms_to_review',
            ],
            'properties' => [
                'title' => ['type' => 'string'],
                'lead' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'seo_title' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
                'seo_keywords' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'translator_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'terms_to_review' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * ETAP 13: jeden artykuł tworzy jeden pakiet wejściowy do OpenAI.
     *
     * @param array<string, mixed> $article
     * @param array<int, string> $targetLanguages
     * @return array<string, mixed>
     */
    public function packagePayload(array $article, string $sourceLanguage, array $targetLanguages, string $instructions): array
    {
        return [
            'task' => 'article_translation_package_for_one_article_v1',
            'article_id' => (int)($article['id'] ?? 0),
            'source_language' => $sourceLanguage,
            'target_languages' => array_values($targetLanguages),
            'translation_instruction' => $instructions,
            'article' => [
                'title' => (string)($article['title'] ?? ''),
                'lead' => (string)($article['lead'] ?? ''),
                'body' => (string)($article['body'] ?? ''),
            ],
            'expected_output' => [
                'format' => 'json',
                'translations' => array_fill_keys(array_values($targetLanguages), [
                    'title' => '',
                    'lead' => '',
                    'body' => '',
                ]),
            ],
            'rules' => [
                'one_article_one_request' => true,
                'one_json_translations_response' => true,
                'no_per_language_requests' => true,
                'do_not_publish' => true,
                'do_not_change_price_premium_status_or_access' => true,
                'return_all_target_languages' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $article
     * @param array<int, string> $targetLanguages
     */
    public function userPromptPackage(array $article, string $sourceLanguage, array $targetLanguages, string $instructions): string
    {
        $payload = $this->packagePayload($article, $sourceLanguage, $targetLanguages, $instructions);

        return 'Przetłumacz jeden tekst na wskazane języki. Zwróć wyłącznie JSON zgodny ze schematem. Dane wejściowe JSON:' . "\n" .
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<int, string> $targetLanguages
     * @return array<string, mixed>
     */
    public function outputSchemaPackage(array $targetLanguages): array
    {
        $translationProperties = [];
        foreach (array_values($targetLanguages) as $language) {
            $translationProperties[$language] = [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['title', 'lead', 'body'],
                'properties' => [
                    'title' => ['type' => 'string'],
                    'lead' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                ],
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['article_id', 'translations'],
            'properties' => [
                'article_id' => ['type' => 'integer'],
                'translations' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array_values($targetLanguages),
                    'properties' => $translationProperties,
                ],
            ],
        ];
    }

}
