<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * P-03 down-vote reason: the fixed choices and the free-text rule.
 *
 * Choices are a closed enum (DB ENUM in migrations/0099_add_vote_reasons.sql);
 * anything else is refused, never defaulted. The note is plain text: trimmed,
 * at most NOTE_MAX_LENGTH characters (code points, not bytes), no control
 * characters. It is never rendered as HTML by any surface.
 */
final class VoteReason
{
    public const NOT_NEEDED         = 'not_needed';
    public const TOO_EXPENSIVE      = 'too_expensive';
    public const WORSENS_WORKFLOW   = 'worsens_workflow';
    public const OTHER              = 'other';

    /** @var list<string> */
    public const CHOICES = [self::NOT_NEEDED, self::TOO_EXPENSIVE, self::WORSENS_WORKFLOW, self::OTHER];

    public const NOTE_MAX_LENGTH = 200;

    public static function isChoice(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::CHOICES, true);
    }

    /**
     * Returns the trimmed note, or null when empty. Throws on a wrong type,
     * a control character or more than NOTE_MAX_LENGTH code points.
     *
     * @throws \InvalidArgumentException with message 'invalid_note' or 'note_too_long'
     */
    public static function normalizeNote(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
            throw new \InvalidArgumentException('invalid_note');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw) === 1) {
            throw new \InvalidArgumentException('invalid_note');
        }

        $note = trim($raw);
        if (mb_strlen($note, 'UTF-8') > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException('note_too_long');
        }

        return $note === '' ? null : $note;
    }
}
