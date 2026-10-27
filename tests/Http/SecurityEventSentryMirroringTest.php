<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Mail\RecordingErrorReporter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * OWASP/BSI audit remediation issue 12: the deliberately small, hand-picked
 * subset of security events additionally mirrored to Sentry (free-tier
 * quota discipline — most audit events are NOT mirrored).
 */
final class SecurityEventSentryMirroringTest extends IntegrationTestCase
{
    /** @return array{0: array<string, string>, 1: string} */
    private function csrfCookieAndToken(): array
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();
        return [[$csrf->cookieName() => $csrf->sign($token)], $token];
    }

    public function test_a_member_role_change_triggers_a_sentry_mirror(): void
    {
        $reporter = new RecordingErrorReporter();

        $ownerId = $this->insertUser('owner-sentry-role@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-sentry-role@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        [$cookie, $token] = $this->csrfCookieAndToken();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/admin/members/{$modId}/role")
            ->withCookieParams($cookie + ['votepit_sess' => $this->sessionCookie($ownerId)])
            ->withParsedBody(['_csrf' => $token, 'role' => 'admin']);

        $response = $this->createApp(errorReporter: $reporter)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $reporter->securityEvents);
        self::assertSame('member.role_changed', $reporter->securityEvents[0]['message']);
        self::assertSame('info', $reporter->securityEvents[0]['level']);
        self::assertArrayNotHasKey('email', $reporter->securityEvents[0]['context']);
    }

    public function test_a_member_removal_does_not_trigger_a_sentry_mirror(): void
    {
        $reporter = new RecordingErrorReporter();

        $ownerId = $this->insertUser('owner-sentry-remove@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-sentry-remove@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        [$cookie, $token] = $this->csrfCookieAndToken();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/admin/members/{$modId}/remove")
            ->withCookieParams($cookie + ['votepit_sess' => $this->sessionCookie($ownerId)])
            ->withParsedBody(['_csrf' => $token]);

        $response = $this->createApp(errorReporter: $reporter)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        // member.removed is audit-logged but is deliberately NOT one of the
        // small set of events mirrored to Sentry (quota discipline).
        self::assertSame([], $reporter->securityEvents);
    }
}
