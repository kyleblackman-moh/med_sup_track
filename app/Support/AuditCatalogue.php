<?php

namespace App\Support;

use App\Enums\AuditAction;

/**
 * IT-ADMIN: how the audit log viewer groups and judges rows. A category is
 * the part of the action before the first dot (auth.login.failed -> auth).
 * A row's outcome is "denied" when its action is a refusal or a failure of
 * access; every other row is "allowed". Both are derived from the action, so
 * filtering on them uses the (action, occurred_at) index.
 */
final class AuditCatalogue
{
    /** Actions that record a refusal or failed access. */
    public const DENIED = [
        AuditAction::LoginFailed,
        AuditAction::AccountLocked,
        AuditAction::RateLimited,
        AuditAction::MfaChallengeFailed,
        AuditAction::AccessDenied,
    ];

    /** Category => label, in display order. */
    public const CATEGORIES = [
        'auth' => 'Sign-in',
        'mfa' => 'Multi-factor authentication',
        'access' => 'Access',
        'user' => 'Users',
        'organisation' => 'Organisations',
        'case' => 'Cases',
        'draft' => 'Drafts',
        'query' => 'Queries',
        'document' => 'Documents',
        'notification' => 'Notifications',
        'mail' => 'Email',
        'job' => 'Queue jobs',
        'log' => 'Audit log',
    ];

    /** @return list<string> */
    public static function deniedActions(): array
    {
        return array_map(fn (AuditAction $a) => $a->value, self::DENIED);
    }

    public static function isDenied(string $action): bool
    {
        return in_array($action, self::deniedActions(), true);
    }

    public static function category(string $action): string
    {
        return explode('.', $action, 2)[0];
    }

    public static function categoryLabel(string $action): string
    {
        $category = self::category($action);

        return self::CATEGORIES[$category] ?? ucfirst($category);
    }

    /** @return list<string> every action value the application writes */
    public static function actions(): array
    {
        return array_map(fn (AuditAction $a) => $a->value, AuditAction::cases());
    }

    /** @return list<string> the actions of one category */
    public static function actionsIn(string $category): array
    {
        return array_values(array_filter(self::actions(), fn (string $a) => self::category($a) === $category));
    }

    /** Options for the filters. */
    public static function categoryOptions(): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(self::CATEGORIES), self::CATEGORIES);
    }

    public static function actionOptions(): array
    {
        $actions = self::actions();
        sort($actions);

        return array_map(fn (string $a) => ['value' => $a, 'label' => $a, 'category' => self::category($a)], $actions);
    }

    public static function outcomeOptions(): array
    {
        return [
            ['value' => 'allowed', 'label' => 'Allowed'],
            ['value' => 'denied', 'label' => 'Denied'],
        ];
    }
}
