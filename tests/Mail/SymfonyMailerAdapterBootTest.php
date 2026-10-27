<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Regression: the app must boot with the default mailer (SymfonyMailerAdapter)
 * and an empty SMTP configuration. The adapter used to build the transport
 * eagerly in the constructor and threw an exception immediately on an empty
 * DSN — the app then wouldn't start for ANY route (not even plain page views).
 */
final class SymfonyMailerAdapterBootTest extends IntegrationTestCase
{
    public function test_app_boots_with_default_mailer_and_empty_smtp(): void
    {
        // No mailer injected → AppFactory builds the real SymfonyMailerAdapter.
        // SMTP is empty (host=''), as in a fresh dev config.
        $app = AppFactory::create(
            $this->testConfig(),
            $this->conn,
            null,
            new AuditLogger($this->logFile),
        );

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
