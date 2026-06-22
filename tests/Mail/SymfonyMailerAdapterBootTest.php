<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Regression: Die App muss mit dem Default-Mailer (SymfonyMailerAdapter) und
 * leerer SMTP-Konfiguration booten. Früher baute der Adapter den Transport
 * eager im Konstruktor und warf bei leerem DSN sofort eine Exception — die App
 * startete dann für KEINE Route (auch nicht für reine Seitenansichten).
 */
final class SymfonyMailerAdapterBootTest extends IntegrationTestCase
{
    public function test_app_boots_with_default_mailer_and_empty_smtp(): void
    {
        // Kein Mailer injiziert → AppFactory baut den echten SymfonyMailerAdapter.
        // SMTP ist leer (host=''), wie in einer frischen Dev-Config.
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
