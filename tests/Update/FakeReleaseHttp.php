<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

use Votepit\Update\ReleaseHttp;

/** In-memory ReleaseHttp: JSON answers by URL, downloads copied from local files. */
final class FakeReleaseHttp implements ReleaseHttp
{
    /** @var array<string, string> url => JSON body */
    public array $bodies = [];

    /** @var array<string, string> url => local source file */
    public array $files = [];

    /** @var list<string> every GET URL requested, in order */
    public array $requested = [];

    public function get(string $url, array $headers, int $timeoutSeconds): ?string
    {
        $this->requested[] = $url;

        return $this->bodies[$url] ?? null;
    }

    public function download(string $url, string $targetPath, int $timeoutSeconds, int $maxBytes): bool
    {
        if (!isset($this->files[$url])) {
            return false;
        }

        return copy($this->files[$url], $targetPath);
    }
}
