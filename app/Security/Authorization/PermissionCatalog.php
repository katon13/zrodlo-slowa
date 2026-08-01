<?php
declare(strict_types=1);

namespace App\Security\Authorization;

final class PermissionCatalog
{
    public const AI_VIEW = 'ai.view';
    public const AI_JOB_PLAN = 'ai.job.plan';
    public const AI_PROMPT_MANAGE = 'ai.prompt.manage';
    public const AI_PROVIDER_TEST = 'ai.provider.test';
    public const AI_SETTINGS_MANAGE = 'ai.settings.manage';
    public const AI_TRANSLATION_RUN = 'ai.translation.run';
    public const AI_BANNER_TRANSLATE = 'ai.banner.translate';
    public const ARTICLE_EDIT = 'article.edit';
    public const ARTICLE_PUBLISH = 'article.publish';
    public const AUDIT_VIEW = 'audit.view';
    public const FINANCE_APPROVAL_REQUEST = 'finance.approval.request';
    public const FINANCE_APPROVAL_REVIEW = 'finance.approval.review';
    public const FINANCE_RECONCILE = 'finance.reconcile';
    public const PAYOUT_REVIEW = 'payout.review';
    public const ROLE_MANAGE = 'role.manage';

    /**
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        return [
            'admin' => ['*'],
            'chief_editor' => [
                self::AI_VIEW,
                self::AI_JOB_PLAN,
                self::AI_PROMPT_MANAGE,
                self::AI_TRANSLATION_RUN,
                self::AI_BANNER_TRANSLATE,
                self::ARTICLE_EDIT,
                self::ARTICLE_PUBLISH,
                self::AUDIT_VIEW,
            ],
            'editor' => [
                self::AI_VIEW,
                self::AI_JOB_PLAN,
                self::AI_PROMPT_MANAGE,
                self::AI_TRANSLATION_RUN,
                self::ARTICLE_EDIT,
            ],
            'publisher' => [
                self::AI_VIEW,
                self::AI_JOB_PLAN,
                self::ARTICLE_PUBLISH,
                self::FINANCE_APPROVAL_REQUEST,
            ],
            'moderator' => [
                self::AI_VIEW,
                self::AI_TRANSLATION_RUN,
                self::ARTICLE_EDIT,
            ],
            'proofreader' => [
                self::ARTICLE_EDIT,
            ],
            'accountant' => [
                self::AUDIT_VIEW,
                self::FINANCE_APPROVAL_REVIEW,
                self::FINANCE_RECONCILE,
                self::PAYOUT_REVIEW,
            ],
        ];
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public static function forRoles(array $roles): array
    {
        $permissions = [];
        foreach (array_unique($roles) as $role) {
            foreach (self::rolePermissions()[$role] ?? [] as $permission) {
                $permissions[$permission] = true;
            }
        }
        return array_keys($permissions);
    }

    /**
     * @param list<string> $roles
     */
    public static function allows(array $roles, string $permission): bool
    {
        $permissions = self::forRoles($roles);
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function requiresStrongAuthentication(string $permission): bool
    {
        return in_array($permission, [
            self::AI_JOB_PLAN,
            self::AI_PROMPT_MANAGE,
            self::AI_PROVIDER_TEST,
            self::AI_SETTINGS_MANAGE,
            self::AI_TRANSLATION_RUN,
            self::AI_BANNER_TRANSLATE,
            self::FINANCE_APPROVAL_REQUEST,
            self::FINANCE_APPROVAL_REVIEW,
            self::FINANCE_RECONCILE,
            self::PAYOUT_REVIEW,
            self::ROLE_MANAGE,
        ], true);
    }
}
