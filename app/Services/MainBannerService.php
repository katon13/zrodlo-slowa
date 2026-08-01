<?php
namespace App\Services;

use App\Contracts\AiProviderInterface;
use App\Core\Database;

final class MainBannerService
{
    private const DEFAULT_BUTTON_URL = '/register';
    private const DEFAULT_IMAGE_PATH = '/assets/img/banners/main-banner-editorial-soft-bg.webp';
    private const HOME_SLUG = 'home-main';

    /** @var array<int, string> */
    private array $defaultLanguages = ['pl', 'en', 'de', 'fr', 'it', 'es'];

    public function __construct(private readonly Database $db) {}

    /**
     * Publiczny Baner Główny dla strony głównej.
     *
     * @return array<string, mixed>|null
     */
    public function activeForPublic(string $language = 'pl'): ?array
    {
        try {
            $banner = $this->db->one('SELECT * FROM main_banners WHERE slug = :slug AND is_active = 1 LIMIT 1', ['slug' => self::HOME_SLUG]);
            if (!$banner) {
                return null;
            }

            $translation = $this->translationForBanner((int)$banner['id'], $language);
            $fallback = $this->defaultBanner($language);

            return [
                'id' => (int)$banner['id'],
                'kicker' => $this->displayText(trim((string)($translation['kicker'] ?? '')) ?: (string)$fallback['kicker']),
                'title' => $this->displayText(trim((string)($translation['title'] ?? '')) ?: (string)$fallback['title']),
                'lead' => $this->displayText(trim((string)($translation['lead_text'] ?? '')) ?: (string)$fallback['lead']),
                'body' => $this->displayText(trim((string)($translation['body_text'] ?? '')) ?: (string)$fallback['body']),
                'button_label' => $this->displayText(trim((string)($translation['button_label'] ?? '')) ?: (string)$fallback['button_label']),
                'button_url' => trim((string)($banner['button_url'] ?? '')) ?: self::DEFAULT_BUTTON_URL,
                'image_path' => trim((string)($banner['image_path'] ?? '')) ?: self::DEFAULT_IMAGE_PATH,
                'is_active' => true,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Dane do formularza administracyjnego BANER GŁÓWNY.
     *
     * @param array<int, string> $languages
     * @return array<string, mixed>
     */
    public function forAdmin(array $languages): array
    {
        $languages = $this->normalizeLanguageList($languages);
        $banner = $this->ensureHomeBanner();
        $id = (int)$banner['id'];
        $translations = [];

        foreach ($languages as $language) {
            $row = $this->db->one('SELECT kicker, title, lead_text, body_text, button_label, updated_at FROM main_banner_translations WHERE banner_id = :id AND language = :language LIMIT 1', [
                'id' => $id,
                'language' => $language,
            ]);
            $fallback = $this->defaultBanner($language);
            $translations[$language] = [
                'kicker' => $this->displayText((string)($row['kicker'] ?? $fallback['kicker'])),
                'title' => $this->displayText((string)($row['title'] ?? $fallback['title'])),
                'lead_text' => $this->displayText((string)($row['lead_text'] ?? $fallback['lead'])),
                'body_text' => $this->displayText((string)($row['body_text'] ?? $fallback['body'])),
                'button_label' => $this->displayText((string)($row['button_label'] ?? $fallback['button_label'])),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }

        return [
            'id' => $id,
            'slug' => (string)($banner['slug'] ?? self::HOME_SLUG),
            'source_language' => 'pl',
            'button_url' => (string)($banner['button_url'] ?? self::DEFAULT_BUTTON_URL),
            'image_path' => (string)($banner['image_path'] ?? self::DEFAULT_IMAGE_PATH),
            'is_active' => (int)($banner['is_active'] ?? 1) === 1,
            'translations' => $translations,
        ];
    }

    /**
     * Zapis formularza administracyjnego BANER GŁÓWNY.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $languages
     */
    public function updateFromAdmin(array $data, array $languages): void
    {
        $languages = $this->normalizeLanguageList($languages);
        $banner = $this->ensureHomeBanner();
        $id = (int)$banner['id'];

        $buttonUrl = $this->safePublicUrl(
            (string)($data['button_url'] ?? self::DEFAULT_BUTTON_URL),
            self::DEFAULT_BUTTON_URL,
            'linku przycisku'
        );
        $imagePath = $this->safePublicUrl(
            (string)($data['image_path'] ?? self::DEFAULT_IMAGE_PATH),
            self::DEFAULT_IMAGE_PATH,
            'obrazu baneru'
        );
        $isActive = !empty($data['is_active']) ? 1 : 0;

        $translations = is_array($data['translations'] ?? null) ? $data['translations'] : [];

        $this->db->transaction(function (Database $db) use ($id, $buttonUrl, $imagePath, $isActive, $translations, $languages): void {
            $db->query('UPDATE main_banners SET button_url = :button_url, image_path = :image_path, is_active = :is_active, updated_at = NOW() WHERE id = :id', [
                'button_url' => $buttonUrl,
                'image_path' => $imagePath,
                'is_active' => $isActive,
                'id' => $id,
            ]);

            foreach ($languages as $language) {
                $row = is_array($translations[$language] ?? null) ? $translations[$language] : [];
                $defaults = $this->defaultBanner($language);
                $this->upsertTranslation($id, $language, [
                    'kicker' => $this->cleanText((string)($row['kicker'] ?? $defaults['kicker']), 255),
                    'title' => $this->cleanText((string)($row['title'] ?? $defaults['title']), 255),
                    'lead_text' => $this->cleanTextarea((string)($row['lead_text'] ?? $defaults['lead'])),
                    'body_text' => $this->cleanTextarea((string)($row['body_text'] ?? $defaults['body'])),
                    'button_label' => $this->cleanText((string)($row['button_label'] ?? $defaults['button_label']), 255),
                ], $db);
            }
        });
    }

    /**
     * Tłumaczenie Baneru Głównego przez AI, podobnie jak przy artykułach.
     * Źródłem jest wersja PL zapisana w tabeli main_banner_translations.
     *
     * @param array<int, string> $targetLanguages
     * @return array<string, mixed>
     */
    public function translateWithAi(AiProviderInterface $client, AiFoundationService $aiFoundation, array $targetLanguages, string $instructions, int $adminId, array $languageConfig = []): array
    {
        $settings = $aiFoundation->settings();
        if (($settings['ai.enabled'] ?? '0') !== '1') {
            throw new \RuntimeException('AI jest wyłączone w ustawieniach administracyjnych.');
        }
        if (($settings['ai.translation.enabled'] ?? '0') !== '1') {
            throw new \RuntimeException('Tłumaczenia AI są wyłączone w ustawieniach administracyjnych.');
        }
        if (($settings['ai.jobs.execute_api_enabled'] ?? '0') !== '1') {
            throw new \RuntimeException('Wywołania API AI są wyłączone. Włącz je świadomie w panelu AI przed generowaniem.');
        }
        if (!$client->configured()) {
            throw new \RuntimeException('Brak skonfigurowanego klucza OpenAI.');
        }
        $aiFoundation->assertTranslationLimits();

        $languages = $this->normalizeLanguageList($targetLanguages);
        $languages = array_values(array_filter($languages, static fn(string $lang): bool => $lang !== 'pl'));
        if ($languages === []) {
            throw new \RuntimeException('Brak języków docelowych do tłumaczenia Baneru Głównego.');
        }

        $banner = $this->ensureHomeBanner();
        $bannerId = (int)$banner['id'];
        $source = $this->translationForBanner($bannerId, 'pl') ?: $this->defaultBanner('pl');

        $sourcePayload = [
            'kicker' => (string)($source['kicker'] ?? ''),
            'title' => (string)($source['title'] ?? ''),
            'lead_text' => (string)($source['lead_text'] ?? ($source['lead'] ?? '')),
            'body_text' => (string)($source['body_text'] ?? ($source['body'] ?? '')),
            'button_label' => (string)($source['button_label'] ?? ''),
        ];

        $defaultInstructions = trim((string)($settings['ai.translation.default_instruction'] ?? ''));
        $instructions = trim($instructions) !== '' ? trim($instructions) : $defaultInstructions;
        $model = trim((string)($settings['ai.translation.model'] ?? ($settings['ai.openai.model'] ?? 'gpt-5.5')));
        if ($model === '') {
            $model = 'gpt-5.5';
        }

        $schema = $this->bannerTranslationSchema($languages);
        $inputJson = [
            'type' => 'main_banner_translation',
            'source_language' => 'pl',
            'target_languages' => $languages,
            'source' => $sourcePayload,
            'instructions' => $instructions,
        ];
        $jobId = 0;

        try {
            $jobId = $this->db->insert(
                'INSERT INTO ai_jobs(public_id,user_id,type,provider,model,status,prompt_code,prompt_version,input_hash,input_json,queued_at,started_at,created_at,updated_at)
                 VALUES(:public_id,:user_id,\'main_banner_translation\',\'openai\',:model,\'running\',\'main_banner_translation\',\'v1\',:input_hash,CAST(:input_json AS JSON),NOW(),NOW(),NOW(),NOW())',
                [
                    'public_id' => 'ai_banner_' . bin2hex(random_bytes(12)),
                    'user_id' => $adminId,
                    'model' => $model,
                    'input_hash' => hash('sha256', json_encode($inputJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'input_json' => json_encode($inputJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (\Throwable) {
            $jobId = 0;
        }

        try {
            $result = $client->structuredJson(
                $this->bannerSystemPrompt(),
                $this->bannerUserPrompt($sourcePayload, $languages, $instructions, $languageConfig),
                $schema,
                $model
            );

            $translations = $this->validateAiBannerTranslations($result['data'] ?? [], $languages);
            $saved = [];

            $this->db->transaction(function (Database $db) use ($bannerId, $translations, &$saved): void {
                foreach ($translations as $language => $translation) {
                    $this->upsertTranslation($bannerId, $language, $translation, $db);
                    $saved[] = $language;
                }
            });

            if ($jobId > 0) {
                $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
                try {
                    $this->db->query(
                        'UPDATE ai_jobs SET status=\'completed\', output_json=CAST(:output_json AS JSON), tokens_input=:tokens_input, tokens_output=:tokens_output, completed_at=NOW(), updated_at=NOW() WHERE id=:id',
                        [
                            'id' => $jobId,
                            'output_json' => json_encode($result['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'tokens_input' => (int)($usage['input_tokens'] ?? 0),
                            'tokens_output' => (int)($usage['output_tokens'] ?? 0),
                        ]
                    );
                } catch (\Throwable) {}
            }

            return [
                'saved_languages' => $saved,
                'ai_job_id' => $jobId ?: null,
                'model' => (string)($result['model'] ?? $model),
            ];
        } catch (\Throwable $e) {
            if ($jobId > 0) {
                try {
                    $this->db->query('UPDATE ai_jobs SET status=\'error\', error_message=:message, completed_at=NOW(), updated_at=NOW() WHERE id=:id', [
                        'id' => $jobId,
                        'message' => mb_substr($e->getMessage(), 0, 1000),
                    ]);
                } catch (\Throwable) {}
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureHomeBanner(): array
    {
        $banner = $this->db->one('SELECT * FROM main_banners WHERE slug = :slug LIMIT 1', ['slug' => self::HOME_SLUG]);
        if ($banner) {
            return $banner;
        }

        $id = $this->db->insert('INSERT INTO main_banners (slug, button_url, image_path, is_active, created_at) VALUES (:slug, :button_url, :image_path, 1, NOW())', [
            'slug' => self::HOME_SLUG,
            'button_url' => self::DEFAULT_BUTTON_URL,
            'image_path' => self::DEFAULT_IMAGE_PATH,
        ]);

        foreach ($this->defaultLanguages as $language) {
            $defaults = $this->defaultBanner($language);
            $this->upsertTranslation($id, $language, [
                'kicker' => (string)$defaults['kicker'],
                'title' => (string)$defaults['title'],
                'lead_text' => (string)$defaults['lead'],
                'body_text' => (string)$defaults['body'],
                'button_label' => (string)$defaults['button_label'],
            ]);
        }

        return $this->db->one('SELECT * FROM main_banners WHERE id = :id LIMIT 1', ['id' => $id]) ?: [
            'id' => $id,
            'slug' => self::HOME_SLUG,
            'button_url' => self::DEFAULT_BUTTON_URL,
            'image_path' => self::DEFAULT_IMAGE_PATH,
            'is_active' => 1,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function translationForBanner(int $bannerId, string $language): ?array
    {
        $language = $this->normalizeLanguage($language) ?: 'pl';
        $translation = $this->db->one('SELECT kicker, title, lead_text, body_text, button_label FROM main_banner_translations WHERE banner_id = :id AND language = :language LIMIT 1', [
            'id' => $bannerId,
            'language' => $language,
        ]);

        if (!$translation && $language !== 'pl') {
            $translation = $this->db->one('SELECT kicker, title, lead_text, body_text, button_label FROM main_banner_translations WHERE banner_id = :id AND language = :language LIMIT 1', [
                'id' => $bannerId,
                'language' => 'pl',
            ]);
        }

        return $translation;
    }

    /**
     * @param array<string, string> $payload
     */
    private function upsertTranslation(int $bannerId, string $language, array $payload, ?Database $db = null): void
    {
        $db ??= $this->db;
        $language = $this->normalizeLanguage($language) ?: 'pl';
        $payload = [
            'banner_id' => $bannerId,
            'language' => $language,
            'kicker' => $this->cleanText((string)($payload['kicker'] ?? ''), 255),
            'title' => $this->cleanText((string)($payload['title'] ?? ''), 255),
            'lead_text' => $this->cleanTextarea((string)($payload['lead_text'] ?? '')),
            'body_text' => $this->cleanTextarea((string)($payload['body_text'] ?? '')),
            'button_label' => $this->cleanText((string)($payload['button_label'] ?? ''), 255),
        ];

        $exists = $db->cell('SELECT id FROM main_banner_translations WHERE banner_id = :banner_id AND language = :language LIMIT 1', [
            'banner_id' => $bannerId,
            'language' => $language,
        ]);

        if ($exists) {
            $payload['id'] = (int)$exists;
            $db->query('UPDATE main_banner_translations SET kicker = :kicker, title = :title, lead_text = :lead_text, body_text = :body_text, button_label = :button_label, updated_at = NOW() WHERE id = :id', [
                'kicker' => $payload['kicker'],
                'title' => $payload['title'],
                'lead_text' => $payload['lead_text'],
                'body_text' => $payload['body_text'],
                'button_label' => $payload['button_label'],
                'id' => $payload['id'],
            ]);
            return;
        }

        $db->query('INSERT INTO main_banner_translations (banner_id, language, kicker, title, lead_text, body_text, button_label, created_at) VALUES (:banner_id, :language, :kicker, :title, :lead_text, :body_text, :button_label, NOW())', $payload);
    }

    private function normalizeLanguage(string $language): string
    {
        return preg_replace('/[^a-z]/', '', strtolower($language)) ?: '';
    }

    /** @param array<int, string> $languages @return array<int, string> */
    private function normalizeLanguageList(array $languages): array
    {
        $out = [];
        foreach ($languages ?: $this->defaultLanguages as $language) {
            $language = $this->normalizeLanguage((string)$language);
            if ($language !== '' && !in_array($language, $out, true)) {
                $out[] = $language;
            }
        }
        return $out !== [] ? $out : $this->defaultLanguages;
    }

    private function cleanText(string $value, int $limit): string
    {
        $value = $this->repairEncodingArtifacts(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit);
        }
        return substr($value, 0, $limit);
    }

    private function cleanTextarea(string $value): string
    {
        return $this->repairEncodingArtifacts(trim(str_replace(["\r\n", "\r"], "\n", $value)));
    }

    private function safePublicUrl(string $value, string $default, string $label): string
    {
        $value = $this->cleanText($value, 255);
        if ($value === '') {
            return $default;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        if (
            in_array($scheme, ['http', 'https'], true)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
        ) {
            return $value;
        }
        throw new \InvalidArgumentException('Nieprawidłowy adres ' . $label . '. Użyj ścieżki /... albo pełnego adresu http(s).');
    }


    private function displayText(string $value): string
    {
        return $this->repairEncodingArtifacts($value);
    }

    /**
     * Naprawa najczęstszych śladów źle zapisanego UTF-8 w tłumaczeniach Baneru Głównego.
     * Nie zmienia poprawnych znaków. Usuwa tylko konkretne sekwencje typu: ┬┐, ├│, ΓÇÖ, Ã©.
     */
    private function repairEncodingArtifacts(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $map = [
            '┬┐' => '¿', '┬í' => '¡', '┬á' => ' ',
            '├í' => 'á', '├á' => 'à', '├⌐' => 'é', '├¿' => 'è', '├¬' => 'ê',
            '├¡' => 'í', '├│' => 'ó', '├║' => 'ú', '├▒' => 'ñ', '├╝' => 'ü',
            '├º' => 'ç', '├ë' => 'É', '├Ç' => 'À', '├ê' => 'È', '├è' => 'Ê',
            '├Ä' => 'Î', '├Å' => 'Ï', '├ö' => 'Ô', '├Ö' => 'Ù', '├¢' => 'Û', '├ç' => 'Ç',
            '┼ô' => 'œ', '┼Æ' => 'Œ',
            'ΓÇÖ' => '’', 'ΓÇ£' => '“', 'ΓÇ¥' => '”', 'ΓÇô' => '–', 'ΓÇö' => '—',
            'Ã¡' => 'á', 'Ã ' => 'à', 'Ã©' => 'é', 'Ã¨' => 'è', 'Ãª' => 'ê',
            'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã±' => 'ñ', 'Ã¼' => 'ü', 'Ã§' => 'ç',
            'Ã‰' => 'É', 'Ã€' => 'À', 'Ãˆ' => 'È', 'ÃŠ' => 'Ê', 'ÃŽ' => 'Î', 'Ã' => 'Ï',
            'Ã”' => 'Ô', 'Ã™' => 'Ù', 'Ã›' => 'Û', 'Ã‡' => 'Ç',
            'Â¿' => '¿', 'Â¡' => '¡', 'Â ' => ' ', 'â€™' => '’', 'â€œ' => '“', 'â€' => '”',
            'â€“' => '–', 'â€”' => '—', 'Å“' => 'œ', 'Å’' => 'Œ',
        ];

        return strtr($value, $map);
    }

    /** @return array<string, mixed> */
    private function defaultBanner(string $language = 'pl'): array
    {
        return [
            'id' => 0,
            'kicker' => function_exists('t') ? t('home.hero.kicker', $language) : 'Masz coś do powiedzenia?',
            'title' => function_exists('t') ? t('home.hero.title', $language) : 'Daj temu własne źródło.',
            'lead' => function_exists('t') ? t('home.hero.subtitle', $language) : 'Tekst, historię, myśl albo informację, która ma wartość? Publikuj tam, gdzie autor nie traci głosu ani zysku.',
            'body' => function_exists('t') ? t('home.hero.description', $language) : 'ŹRÓDŁO SŁOWA to wirtualna redakcja dla autorów, dziennikarzy i ludzi, których teksty, historie, informacje i opinie mają realną wartość.',
            'button_label' => function_exists('t') ? t('home.hero.cta_author', $language) : 'Dołącz jako autor',
            'button_url' => self::DEFAULT_BUTTON_URL,
            'image_path' => self::DEFAULT_IMAGE_PATH,
        ];
    }

    private function bannerSystemPrompt(): string
    {
        return 'Jesteś redaktorem tłumaczem systemu ŹRÓDŁO SŁOWA. Tłumaczysz krótki Baner Główny strony. Zwracasz wyłącznie JSON zgodny ze schematem. Nie dopisujesz nowych treści, nie streszczasz i nie zmieniasz sensu. Zachowujesz spokojny, elegancki styl portalu.';
    }

    /** @param array<string, string> $source @param array<int, string> $languages @param array<string, mixed> $languageConfig */
    private function bannerUserPrompt(array $source, array $languages, string $instructions, array $languageConfig): string
    {
        $labels = is_array($languageConfig['labels'] ?? null) ? $languageConfig['labels'] : [];
        $languageNames = [];
        foreach ($languages as $language) {
            $languageNames[$language] = (string)($labels[$language] ?? strtoupper($language));
        }

        return "Przetłumacz Baner Główny z języka polskiego na wskazane języki.\n"
            . "Języki docelowe: " . json_encode($languageNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "Instrukcje redakcyjne: " . ($instructions !== '' ? $instructions : 'Tłumacz naturalnie, wiernie i redakcyjnie.') . "\n"
            . "Pola do tłumaczenia: kicker, title, lead_text, body_text, button_label.\n"
            . "Nie tłumacz linków, ścieżek obrazów ani danych technicznych.\n"
            . "Treść źródłowa PL: " . json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<int, string> $targetLanguages @return array<string, mixed> */
    private function bannerTranslationSchema(array $targetLanguages): array
    {
        $translationProperties = [];
        foreach ($targetLanguages as $language) {
            $translationProperties[$language] = [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['kicker', 'title', 'lead_text', 'body_text', 'button_label'],
                'properties' => [
                    'kicker' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'lead_text' => ['type' => 'string'],
                    'body_text' => ['type' => 'string'],
                    'button_label' => ['type' => 'string'],
                ],
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['translations'],
            'properties' => [
                'translations' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array_values($targetLanguages),
                    'properties' => $translationProperties,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $data @param array<int, string> $languages @return array<string, array<string, string>> */
    private function validateAiBannerTranslations(array $data, array $languages): array
    {
        if (!is_array($data['translations'] ?? null)) {
            throw new \RuntimeException('Wynik AI nie zawiera poprawnego obiektu translations.');
        }

        $out = [];
        foreach ($languages as $language) {
            $row = $data['translations'][$language] ?? null;
            if (!is_array($row)) {
                throw new \RuntimeException('Brak tłumaczenia Baneru Głównego dla języka: ' . $language);
            }
            $out[$language] = [
                'kicker' => $this->cleanText((string)($row['kicker'] ?? ''), 255),
                'title' => $this->cleanText((string)($row['title'] ?? ''), 255),
                'lead_text' => $this->cleanTextarea((string)($row['lead_text'] ?? '')),
                'body_text' => $this->cleanTextarea((string)($row['body_text'] ?? '')),
                'button_label' => $this->cleanText((string)($row['button_label'] ?? ''), 255),
            ];
            if ($out[$language]['title'] === '' || $out[$language]['lead_text'] === '') {
                throw new \RuntimeException('AI zwróciło puste kluczowe pola dla języka: ' . $language);
            }
        }
        return $out;
    }
}
