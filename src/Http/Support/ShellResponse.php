<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

/**
 * The SPA shell HTML for one request together with its HTTP status:
 * 200 for a path that names an existing account and board (or no board at all),
 * 404 when the path names an account or board that does not exist for an
 * anonymous viewer. The body is the shell in both cases.
 */
final readonly class ShellResponse
{
    public function __construct(
        public string $html,
        public int $status = 200,
    ) {}
}
