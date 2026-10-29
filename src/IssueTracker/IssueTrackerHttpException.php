<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

/**
 * Transport-level failure (DNS/connect/timeout/TLS) or a URL refused by the
 * fixed-host allowlist. Never thrown for a completed HTTP response — the
 * caller inspects the status for that. Messages never contain the token.
 */
final class IssueTrackerHttpException extends \RuntimeException {}
