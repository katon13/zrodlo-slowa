<?php
namespace App\Services;

use App\Contracts\AiProviderInterface;

final class OpenAiClient implements AiProviderInterface
{
    public function __construct(private readonly array $config = []) {}

    public function configured(): bool
    {
        return trim((string)($this->config['openai']['api_key'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function structuredJson(string $systemPrompt, string $userPrompt, array $schema, ?string $model = null): array
    {
        $schemaName = isset($schema['properties']['translations'])
            ? 'article_translation_package'
            : 'article_translation_draft';

        $apiKey = trim((string)($this->config['openai']['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Brak klucza OpenAI w ENV / konfiguracji serwera.');
        }

        $model = trim((string)($model ?: ($this->config['openai']['model'] ?? 'gpt-5.5')));
        if ($model === '') {
            throw new \RuntimeException('Brak modelu OpenAI.');
        }

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Rozszerzenie PHP cURL nie jest dostępne.');
        }

        $ch = curl_init('https://api.openai.com/v1/responses');
        if ($ch === false) {
            throw new \RuntimeException('Nie udało się uruchomić klienta HTTP dla OpenAI.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('Błąd połączenia OpenAI: ' . $error);
        }
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('OpenAI zwróciło pustą odpowiedź.');
        }

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new \RuntimeException('OpenAI zwróciło niepoprawny JSON odpowiedzi.');
        }
        if ($status < 200 || $status >= 300) {
            $message = (string)($response['error']['message'] ?? ('HTTP ' . $status));
            throw new \RuntimeException('OpenAI zwróciło błąd: ' . mb_substr($message, 0, 500));
        }

        $text = $this->extractOutputText($response);
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new \RuntimeException('OpenAI nie zwróciło poprawnego JSON strukturalnego.');
        }

        return [
            'data' => $data,
            'raw' => $response,
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'model' => (string)($response['model'] ?? $model),
        ];
    }


    /**
     * @return array<string, mixed>
     */
    public function testConnection(?string $model = null): array
    {
        $apiKey = trim((string)($this->config['openai']['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Brak klucza OpenAI w ENV / konfiguracji serwera.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Rozszerzenie PHP cURL nie jest dostępne.');
        }

        $model = trim((string)($model ?: ($this->config['openai']['model'] ?? 'gpt-5.5')));
        if ($model === '') {
            throw new \RuntimeException('Brak modelu OpenAI do testu połączenia.');
        }

        $payload = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => 'Zwróć wyłącznie JSON zgodny ze schematem.'],
                ['role' => 'user', 'content' => 'Test połączenia OpenAI dla panelu administracyjnego. Zwróć status ok.'],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'openai_connection_test',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['status'],
                        'properties' => [
                            'status' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        if ($ch === false) {
            throw new \RuntimeException('Nie udało się uruchomić klienta HTTP dla OpenAI.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('Błąd połączenia OpenAI: ' . $error);
        }
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('OpenAI zwróciło pustą odpowiedź testową.');
        }

        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new \RuntimeException('OpenAI zwróciło niepoprawny JSON odpowiedzi testowej.');
        }
        if ($status < 200 || $status >= 300) {
            $message = (string)($response['error']['message'] ?? ('HTTP ' . $status));
            throw new \RuntimeException('OpenAI zwróciło błąd testu: ' . mb_substr($message, 0, 500));
        }

        $text = $this->extractOutputText($response);
        $data = json_decode($text, true);
        if (!is_array($data) || strtolower((string)($data['status'] ?? '')) !== 'ok') {
            throw new \RuntimeException('Odpowiedź testowa OpenAI nie potwierdza statusu ok.');
        }

        return [
            'status' => 'success',
            'model' => (string)($response['model'] ?? $model),
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'raw' => $response,
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractOutputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        $parts = [];
        foreach (($response['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (!is_array($content)) {
                    continue;
                }
                if (isset($content['text']) && is_string($content['text'])) {
                    $parts[] = $content['text'];
                }
            }
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw new \RuntimeException('Nie znaleziono tekstu wyjściowego w odpowiedzi OpenAI.');
        }
        return $text;
    }
}
