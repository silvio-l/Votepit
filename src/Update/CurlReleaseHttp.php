<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * cURL implementation of ReleaseHttp. HTTPS only (also on redirects), a short
 * timeout for the daily lookups, and a hard byte limit for downloads.
 */
final readonly class CurlReleaseHttp implements ReleaseHttp
{
    /** @param non-empty-string $userAgent */
    public function __construct(private string $userAgent) {}

    public function get(string $url, array $headers, int $timeoutSeconds): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_FOLLOWLOCATION    => true,
            CURLOPT_MAXREDIRS         => 3,
            CURLOPT_PROTOCOLS         => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS   => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT    => $timeoutSeconds,
            CURLOPT_TIMEOUT           => $timeoutSeconds,
            CURLOPT_USERAGENT         => $this->userAgent,
            CURLOPT_HTTPHEADER        => $headers,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $status < 200 || $status > 299) {
            return null;
        }

        return $body;
    }

    public function download(string $url, string $targetPath, int $timeoutSeconds, int $maxBytes): bool
    {
        $handle = @fopen($targetPath, 'wb');
        if ($handle === false) {
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($handle);
            @unlink($targetPath);

            return false;
        }

        $written = 0;
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => $timeoutSeconds,
            CURLOPT_TIMEOUT         => $timeoutSeconds,
            CURLOPT_USERAGENT       => $this->userAgent,
            CURLOPT_WRITEFUNCTION   => static function (mixed $curl, string $chunk) use ($handle, &$written, &$tooLarge, $maxBytes): int {
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    $tooLarge = true;

                    return 0; // aborts the transfer
                }

                return fwrite($handle, $chunk) === strlen($chunk) ? strlen($chunk) : 0;
            },
        ]);

        $ok     = curl_exec($ch) !== false;
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($handle);

        if (!$ok || $tooLarge || $status < 200 || $status > 299) {
            @unlink($targetPath);

            return false;
        }

        return true;
    }
}
