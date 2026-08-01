<?php
namespace App\Models;

final class ArticleTranslation
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_AI_DRAFT = 'ai_draft';
    public const STATUS_EDITOR_REVIEW = 'editor_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ERROR = 'error';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_AI_DRAFT,
        self::STATUS_EDITOR_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_ERROR,
    ];

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
