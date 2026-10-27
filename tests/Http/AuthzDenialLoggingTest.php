<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * OWASP/BSI audit remediation issue 10: every AuthZMiddleware denial writes
 * exactly one sampled `authz.denied` audit-log entry with the expected
 * context, and the denial response body carries no internal class name
 * (generic "Access denied." only).
 */
final class AuthzDenialLoggingTest extends IntegrationTestCase
{
    private function request(string $method, string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        $cookies = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return $request->withCookieParams($cookies);
    }

    /** @return list<array<string, mixed>> */
    private function auditEntries(string $action): array
    {
        $lines = is_file($this->logFile) ? file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $lines = $lines !== false ? $lines : [];

        $entries = [];
        foreach ($lines as $line) {
            // NDJSON format {"ts","action","context","prev","hash"} — see AuditLogger::log().
            $decoded = json_decode($line, true);
            if (!is_array($decoded) || ($decoded['action'] ?? null) !== $action) {
                continue;
            }
            self::assertIsArray($decoded['context']);
            $entries[] = $decoded['context'];
        }
        return $entries;
    }

    public function test_denied_request_logs_exactly_one_authz_denied_entry_with_generic_response(): void
    {
        $userId = $this->insertUser('plain-user@example.com');
        $app    = $this->createApp();

        $response = $app->handle($this->request('GET', '/operator/usage', $userId));

        self::assertSame(403, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertSame('Access denied.', $body);
        self::assertStringNotContainsString('AuthZMiddleware', $body);
        self::assertStringNotContainsString('Votepit\\', $body);

        $entries = $this->auditEntries('authz.denied');
        self::assertCount(1, $entries);
        self::assertSame('operator', $entries[0]['level']);
        self::assertSame($userId, $entries[0]['uid']);
        self::assertSame('/operator/usage', $entries[0]['path']);
    }

    public function test_anon_denied_request_logs_authz_denied_with_null_uid(): void
    {
        $app = $this->createApp();

        $response = $app->handle($this->request('GET', '/operator/usage', null));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Access denied.', (string) $response->getBody());

        $entries = $this->auditEntries('authz.denied');
        self::assertCount(1, $entries);
        self::assertSame('operator', $entries[0]['level']);
        self::assertNull($entries[0]['uid']);
        self::assertSame('/operator/usage', $entries[0]['path']);
    }
}
