<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

/**
 * A failed connect/create call, reduced to a stable, non-secret error key
 * the API returns to the admin UI. Provider error bodies and the token are
 * never part of it (they could echo the token or internal details).
 */
final class IssueTrackerException extends \RuntimeException
{
    public const INVALID_CREDENTIALS = 'issue_tracker_invalid_credentials';
    public const TARGET_NOT_FOUND    = 'issue_tracker_target_not_found';
    public const REJECTED            = 'issue_tracker_rejected';
    public const UNAVAILABLE         = 'issue_tracker_unavailable';

    public function __construct(public readonly string $key)
    {
        parent::__construct($this->key);
    }

    /** 502 when the provider could not be reached, 422 for everything the admin can fix. */
    public function httpStatus(): int
    {
        return $this->key === self::UNAVAILABLE ? 502 : 422;
    }

    /** Generic, non-secret message for the API error envelope. */
    public function userMessage(): string
    {
        return match ($this->key) {
            self::INVALID_CREDENTIALS => 'The access token was rejected.',
            self::TARGET_NOT_FOUND    => 'The team or repository was not found or is not accessible with this token.',
            self::REJECTED            => 'The issue tracker rejected the request.',
            default                   => 'The issue tracker could not be reached. Try again later.',
        };
    }
}
