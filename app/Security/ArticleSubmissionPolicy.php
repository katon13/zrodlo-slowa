<?php
declare(strict_types=1);

namespace App\Security;

final class ArticleSubmissionPolicy
{
    public const TITLE_MAX_CHARACTERS = 240;
    public const LEAD_MAX_CHARACTERS = 2_000;
    public const BODY_MAX_CHARACTERS = 500_000;

    /** @param array<string,mixed> $data */
    public static function validate(array $data): void
    {
        $title = trim((string)($data['title'] ?? ''));
        $lead = trim((string)($data['lead'] ?? ''));
        $body = trim((string)($data['body'] ?? ''));
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('Tytuł i treść są wymagane.');
        }
        if (mb_strlen($title) > self::TITLE_MAX_CHARACTERS) {
            throw new \InvalidArgumentException('Tytuł przekracza limit 240 znaków.');
        }
        if (mb_strlen($lead) > self::LEAD_MAX_CHARACTERS) {
            throw new \InvalidArgumentException('Lead przekracza limit 2000 znaków.');
        }
        if (mb_strlen($body) > self::BODY_MAX_CHARACTERS) {
            throw new \InvalidArgumentException('Treść przekracza limit 500 000 znaków.');
        }
    }
}
