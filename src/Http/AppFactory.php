<?php

declare(strict_types=1);

namespace Votepit\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use Votepit\Config;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\BlockCheckMiddleware;
use Votepit\Http\Middleware\SecurityHeaderMiddleware;
use Votepit\Http\Middleware\SessionMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Security\SessionService;

/**
 * Baut die Slim-4-App mit der PSR-15-Middleware-Pipeline (arch.md L1–L4) und
 * den definierten Routes.
 *
 * Sprint 0: Smoke-Route (GET /), Security-Header, Session/AuthN/AuthZ/BlockCheck
 * als Gerüst. CSRF (slim/csrf), RateLimit und fachliche Routes folgen in
 * Sprint 2+.
 */
final class AppFactory
{
    public static function create(Config $config): App
    {
        $responseFactory = new ResponseFactory();

        $app = new App($responseFactory);

        // --- Services -----------------------------------------------------
        $root     = dirname(__DIR__, 2); // Repo-Root
        $sessions = new SessionService(
            appKey: $config->appKey,
            lifetime: $config->sessionLifetime,
            secure: $config->env === 'prod',
        );
        $audit    = new AuditLogger($root . '/logs/audit.log');

        $twig = Twig::create($root . '/templates', [
            'cache'       => $config->env === 'prod' ? $root . '/var/twig-cache' : false,
            'autoescape'  => 'html', // Sicherheits-Default (A03 — XSS)
            'strict_variables' => false,
        ]);

        // --- Globale PSR-15-Pipeline (Request-Reihenfolge außen → innen) --
        $app->add(new BlockCheckMiddleware($responseFactory));
        $app->add(new AuthNMiddleware());
        $app->add(new SessionMiddleware($sessions));
        $app->add(new SecurityHeaderMiddleware());
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware(
            displayErrorDetails: $config->env === 'dev',
            logErrors: true,
            logErrorDetails: $config->env === 'dev',
        );

        // --- Routes -------------------------------------------------------
        // Smoke-Route: beweist Boot + Pipeline + Twig + Security-Header.
        $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($twig, $audit) {
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
