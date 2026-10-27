<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * The optional public reasoning attached to ONE idea status change (P-01,
 * migration 0091): a plaintext reason, a plaintext next step and a fixed
 * responsible-role label. All three are optional; an absent or blank value
 * becomes null, so a client that sends none of them behaves exactly as before
 * (the change is still recorded, just without reasoning).
 *
 * Plaintext only: the SPA renders these escaped (no Markdown, no HTML), and the
 * control-character filter keeps the value a single safe text block. The role
 * is a fixed key ('team' | 'moderation') that the SPA maps to a label; it never
 * carries a person, a name or an e-mail address.
 */
final readonly class StatusDecision
{
    public const REASON_MAX    = 1000;
    public const NEXT_STEP_MAX = 280;

    /** @var list<string> */
    public const ROLES = ['team', 'moderation'];

    public function __construct(
        public ?string $reason,
        public ?string $nextStep,
        public ?string $responsibleRole,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null);
    }

    /**
     * Parses the three optional request values. Throws InvalidStatusDecision
     * (naming the offending field) instead of silently truncating, so a
     * client never believes a longer reason was stored than actually was.
     *
     * @throws InvalidStatusDecision
     */
    public static function fromInput(mixed $reason, mixed $nextStep, mixed $responsibleRole): self
    {
        $role = self::scalarOrNull($responsibleRole, 'responsible_role');
        $role = $role === null || trim($role) === '' ? null : trim($role);
        if ($role !== null && !in_array($role, self::ROLES, true)) {
            throw new InvalidStatusDecision('responsible_role');
        }

        return new self(
            self::text($reason, 'reason', self::REASON_MAX),
            self::text($nextStep, 'next_step', self::NEXT_STEP_MAX),
            $role,
        );
    }

    /**
     * @throws InvalidStatusDecision
     */
    private static function text(mixed $value, string $field, int $max): ?string
    {
        $text = self::scalarOrNull($value, $field);
        if ($text === null) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Tab, LF and CR are allowed (a reason may span lines); every other
        // control character, NUL included, is rejected outright.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            throw new InvalidStatusDecision($field);
        }

        if (mb_strlen($text, 'UTF-8') > $max) {
            throw new InvalidStatusDecision($field);
        }

        return $text;
    }

    /**
     * Only scalar request values count; arrays and objects are a client
     * error, not something to coerce.
     *
     * @throws InvalidStatusDecision
     */
    private static function scalarOrNull(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidStatusDecision($field);
        }

        return $value;
    }
}
