<?php

declare(strict_types=1);

namespace Votepit\Support;

/**
 * Shared category vocabulary for support-request intake AND the FAQ
 * (support-feature, see migrations/0023_add_support_and_faq.sql). ONE
 * shared list — a support request's category and an FAQ entry's category
 * are deliberately the same enum, so the contact form can deflect a
 * submission by category into matching FAQ entries before the customer ever
 * writes a message (SupportRequestAction/FaqAction both validate against
 * this list; the migration's CHECK constraints mirror it literally, since
 * SQL can't reference PHP constants).
 */
final class SupportCategory
{
    public const BILLING         = 'billing';
    public const TECHNICAL       = 'technical';
    public const ACCOUNT         = 'account';
    public const FEATURE_REQUEST = 'feature_request';
    public const PRIVACY         = 'privacy';
    public const OTHER           = 'other';

    /** @var list<string> */
    public const ALL = [
        self::BILLING,
        self::TECHNICAL,
        self::ACCOUNT,
        self::FEATURE_REQUEST,
        self::PRIVACY,
        self::OTHER,
    ];

    public static function isValid(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }
}
