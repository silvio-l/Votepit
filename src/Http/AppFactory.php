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
use Votepit\Mail\Mailer;
use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\CsrfService;
use Votepit\Security\RateLimiter;
use Votepit\Security\SessionService;
use Votepit\Security\TokenVault;

/**
 * Baut die Slim-4-App mit der PSR-15-Middleware-Pipeline (arch.md L1–L4) und
 * den definierten Routes.
 *
 * Sprint 0: Smoke-Route (GET /), Security-Header, RateLimit(IP)/Session/AuthN/
 * BlockCheck/CSRF als Pipeline, AuthZ per-Route.
 * Sprint 2: GET /login + POST /login (Magic-Link-Request-Flow), Mailer-Seam,
 * UserRepository, LoginTokenRepository.
 *
 * Die DB-Connection ist optional: ohne sie (DB-loser Smoke-Test) entfällt die
 * RateLimit(IP)-Schicht und die Login-Routen werden nicht registriert.
 * Der Mailer ist optional: ohne ihn (Produktion) wird SymfonyMailerAdapter
 * aus der SmtpConfig gebaut; Tests injizieren InMemoryMailer.
 * Der AuditLogger ist optional: ohne ihn wird der dateibasierte Default gebaut.
 */
final class AppFactory
{
    /** @return App<null> */
    public static function create(
        Config $config,
        ?Connection $conn = null,
        ?Mailer $mailer = null,
        ?AuditLogger $auditLogger = null,
    ): App {
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
        $audit    = $auditLogger ?? new AuditLogger($root . '/logs/audit.log');

        $twig = Twig::create($root . '/templates', [
            'cache'            => $config->env === 'prod' ? $root . '/var/twig-cache' : false,
            'autoescape'       => 'html', // Sicherheits-Default (A03 — XSS)
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

        // Login-Routen (Sprint 2): nur mit DB-Connection registrieren.
        if ($conn instanceof Connection) {
            $userRepo  = new UserRepository($conn);
            $tokenRepo = new LoginTokenRepository($conn);
            $vault     = new TokenVault();
            $resolvedMailer = $mailer ?? new SymfonyMailerAdapter($config->smtp);

            // GET /login — zeigt das E-Mail-Formular (AuthZ: anon).
            $app->get('/login', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($twig): MessageInterface {
                $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
                $response  = $twig->render($response, 'login.twig', [
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                ]);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            })->add(AuthZMiddleware::anon($responseFactory));

            // POST /login — verarbeitet die E-Mail, versendet den Magic-Link (AuthZ: anon).
            // Antwort ist IMMER identisch (Anti-Enumeration; AC 3 & 4).
            $app->post('/login', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($twig, $userRepo, $tokenRepo, $vault, $resolvedMailer, $audit, $config): MessageInterface {
                $parsed    = $request->getParsedBody();
                $rawEmail  = is_array($parsed) ? (string) ($parsed['email'] ?? '') : '';
                $email     = strtolower(trim($rawEmail));

                // Nur versenden wenn Syntax gültig — neutrale Behandlung (kein 4xx).
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $user = $userRepo->findByEmail($email) ?? $userRepo->create($email);

                    $tokenRepo->deleteOpenForUser((int) $user['id']);

                    $pair      = $vault->generate();
                    $expiresAt = (new \DateTimeImmutable('+' . $config->magicLinkTtl . ' seconds'))
                        ->format('Y-m-d H:i:s');
                    $tokenRepo->insert((int) $user['id'], $pair['hash'], $expiresAt);

                    $link = $config->appUrl . '/login/verify?token=' . $pair['token'];
                    $resolvedMailer->send(
                        $email,
                        'Dein Votepit Login-Link',
                        "Hallo,\n\nhier ist dein Login-Link:\n\n{$link}\n\nDer Link ist 15 Minuten gültig.\nBitte nicht weitergeben.\n",
                    );

                    // Maskierte E-Mail im Log — Klartext-Token darf NIE ins Log.
                    $audit->log('magic_link.requested', ['email' => $email]);
                }

                $response = $twig->render($response, 'login-sent.twig', []);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            })->add(AuthZMiddleware::anon($responseFactory));
        }

        return $app;
    }
}
