<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * The outbound HTTP the update channel needs: one small GET for the update
 * check and the version report, one streamed download for the updater. Kept as
 * an interface so the decision logic is testable without a network.
 */
interface ReleaseHttp
{
    /**
     * Body of a 2xx answer; null on any other status or transport error.
     *
     * @param list<string> $headers
     */
    public function get(string $url, array $headers, int $timeoutSeconds): ?string;

    /**
     * Streams a 2xx answer into $targetPath. False on a non-2xx status, a
     * transport error or a body larger than $maxBytes; the partial file is removed.
     */
    public function download(string $url, string $targetPath, int $timeoutSeconds, int $maxBytes): bool;
}
