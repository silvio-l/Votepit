<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Votepit\Persistence\TagRepository;

/**
 * Validates the optional `tag_ids` of an idea submission (P-34, owner Q10=a).
 *
 * Participants may pick tags from the board's EXISTING tags only; they can
 * never create one here. Every id must be a positive integer that belongs to
 * the submitted board (and therefore to its account): unknown, foreign-board
 * and foreign-account ids are all rejected with the same `invalid_tag` answer,
 * so the submission is refused as a whole and nothing is stored.
 */
final class IdeaTagInput
{
    /** Maximum number of tags one idea can carry at submission. */
    public const MAX_TAGS = 5;

    /**
     * @return array{ids: list<int>, error: ?string} error is `invalid_tag`,
     *         `too_many_tags`, or null; ids are de-duplicated and ascending
     */
    public static function resolve(?TagRepository $tags, int $boardId, mixed $raw): array
    {
        if (in_array($raw, [null, '', []], true)) {
            return ['ids' => [], 'error' => null];
        }

        if (!is_array($raw) || !array_is_list($raw)) {
            return ['ids' => [], 'error' => 'invalid_tag'];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_string($value) && ctype_digit($value)) {
                $value = (int) $value;
            }
            if (!is_int($value) || $value <= 0) {
                return ['ids' => [], 'error' => 'invalid_tag'];
            }
            $ids[$value] = $value;
        }

        $ids = array_values($ids);
        sort($ids);

        if (count($ids) > self::MAX_TAGS) {
            return ['ids' => [], 'error' => 'too_many_tags'];
        }

        // Fail-secure: without a tag repository no id can be verified.
        if (!$tags instanceof \Votepit\Persistence\TagRepository || count($tags->idsInBoard($boardId, $ids)) !== count($ids)) {
            return ['ids' => [], 'error' => 'invalid_tag'];
        }

        return ['ids' => $ids, 'error' => null];
    }

    /** The message for a rejected tag input (shared by both submit paths). */
    public static function message(string $error): string
    {
        return $error === 'too_many_tags'
            ? 'At most ' . self::MAX_TAGS . ' tags can be chosen.'
            : 'One or more tags are not available on this board.';
    }
}
