<?php

declare(strict_types=1);

namespace Votepit\Http;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use Votepit\Config;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\BlockCheckMiddleware;
use Votepit\Http\Middleware\CsrfMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Http\Middleware\SecurityHeaderMiddleware;
use Votepit\Http\Middleware\SessionMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Security\CsrfService;
use Votepit\Security\RateLimiter;
use Votepit\Security\SessionService;

/**
 * Baut die Slim-4-App mit der PSR-15-Middleware-Pipeline (arch.md L1–L4) und
 * den definierten Routes.
 *
 * Sprint 0: Smoke-Route (GET /), Security-Header, RateLimit(IP)/Session/AuthN/
 * BlockCheck/CSRF als Pipeline, AuthZ per-Route. Magic-Link + fachliche Routes
 * folgen in Sprint 2+.
 *
 * Die DB-Connection ist optional: ohne sie (DB-loser Smoke-Test) entfällt die
 * RateLimit(IP)-Schicht; public/index.php reicht in Produktion eine echte
 * Connection (ConnectionFactory) herein.
 */
final class AppFactory
{
    /** @return App<null> */
    public static function create(Config $config, ?Connection $conn = null): App
    {
        $responseFactory = new ResponseFactory();

        $app = new App($responseFactory);

        // --- Services -----------------------------------------------------
        $root     = dirname(__DIR__, 2); // Repo-Root
        $secure   = $config->env === 'prod';
        $sessions = new SessionService(
            appKey: $config->appKey,
            lifetime: $config->sessionLifetime,
            secure: $secure,
        );
        $csrf     = new CsrfService(
            appKey: $config->appKey,
            lifetime: $config->sessionLifetime,
            secure: $secure,
        );
        $audit    = new AuditLogger($root . '/logs/audit.log');

        $twig = Twig::create($root . '/templates', [
            'cache'       => $config->env === 'prod' ? $root . '/var/twig-cache' : false,
            'autoescape'  => 'html', // Sicherheits-Default (A03 — XSS)
            'strict_variables' => false,
        ]);

        // --- Globale PSR-15-Pipeline -------------------------------------
        // Add-Reihenfolge ist umgekehrt zur Ausführung (zuletzt added = außen).
        // Ausführung außen → innen:
        //   Error → BodyParsing → SecurityHeader → RateLimit(IP) → Session →
        //   AuthN → BlockCheck → CSRF → [Route: AuthZ → Handler]
        $app->add(new CsrfMiddleware($csrf, $responseFactory));
        $app->add(new BlockCheckMiddleware($responseFactory));
        $app->add(new AuthNMiddleware());
        $app->add(new SessionMiddleware($sessions));

        // RateLimit(IP) nur mit DB-Connection (grob, pro Client-IP).
        if ($conn instanceof Connection) {
            $ipLimit = $config->rateLimit('global:ip');
            $app->add(RateLimitMiddleware::perIp(
                new RateLimiter($conn),
                $responseFactory,
                $ipLimit['limit'],
                $ipLimit['window'],
            ));
        }

        $app->add(new SecurityHeaderMiddleware());
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware(
            displayErrorDetails: $config->env === 'dev',
            logErrors: true,
            logErrorDetails: $config->env === 'dev',
        );

        // --- Routes -------------------------------------------------------
        // Smoke-Route: beweist Boot + Pipeline + Twig + Security-Header.
        $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($twig, $audit): MessageInterface {
            $audit->log('smoke.hit', ['ua' => $request->getHeaderLine('User-Agent')]);
            $response = $twig->render($response, 'home.twig', [
                'title'  => 'Votepit',
                'status' => 'Security-Foundation aktiv.',
            ]);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        })->add(AuthZMiddleware::anon($responseFactory));

        return $app;
    }
}
