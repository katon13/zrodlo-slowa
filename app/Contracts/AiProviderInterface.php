<?php
declare(strict_types=1);

namespace App\Contracts;

interface AiProviderInterface
{
    public function configured(): bool;

    /**
     * @return array<string, mixed>
     */
    public function testConnection(?string $model = null): array;

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function structuredJson(string $systemPrompt, string $userPrompt, array $schema, ?string $model = null): array;
}
