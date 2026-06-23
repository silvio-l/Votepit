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
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Action\IdeaCreateAction;
use Votepit\Http\Action\IdeaEditAction;
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
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\BrandingValidator;
use Votepit\Security\CsrfService;
use Votepit\Security\RateLimiter;
use Votepit\Security\ReturnToValidator;
use Votepit\Security\SessionService;
use Votepit\Security\TimeTrapService;
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

        // UserRepository wird (mit DB) bereits für die AuthN-Hydratation gebraucht.
        $userRepo = $conn instanceof Connection ? new UserRepository($conn) : null;

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
        $app->add(new AuthNMiddleware($userRepo));
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
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $response  = $twig->render($response, 'home.twig', [
                'title'            => 'Votepit',
                'status'           => 'Security-Foundation aktiv.',
                'csrf_token'       => is_string($csrfToken) ? $csrfToken : '',
                'is_authenticated' => $request->getAttribute(AuthNMiddleware::ATTR_USER) !== null,
            ]);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        })->add(AuthZMiddleware::anon($responseFactory));

        // Login-Routen (Sprint 2): nur mit DB-Connection registrieren.
        if ($conn instanceof Connection) {
            $userRepo ??= new UserRepository($conn); // bereits oben gebaut; ??= narrowt den Typ
            $tokenRepo = new LoginTokenRepository($conn);
            $boardRepo = new BoardRepository($conn);
            $vault     = new TokenVault();
            $resolvedMailer = $mailer ?? new SymfonyMailerAdapter($config->smtp);

            // Per-Action-Rate-Limits für Magic-Link-Anfragen (Issue 06).
            $emailRateLimit = $config->rateLimit('magiclink:email');
            $mlIpRateLimit  = $config->rateLimit('magiclink:ip');

            // GET /login — zeigt das E-Mail-Formular (AuthZ: anon).
            $app->get('/login', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($twig): MessageInterface {
                $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
                $params    = $request->getQueryParams();
                $rawR      = is_string($params['r'] ?? null) ? $params['r'] : '';
                $returnTo  = ReturnToValidator::isValid($rawR) ? $rawR : '';
                $response  = $twig->render($response, 'login.twig', [
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'return_to'  => $returnTo,
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
                $rawR      = is_array($parsed) ? (string) ($parsed['r'] ?? '') : '';
                $returnTo  = ReturnToValidator::isValid($rawR) ? $rawR : '';

                // Nur versenden wenn Syntax gültig — neutrale Behandlung (kein 4xx).
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $user = $userRepo->findByEmail($email) ?? $userRepo->create($email);

                    $tokenRepo->deleteOpenForUser((int) $user['id']);

                    $pair      = $vault->generate();
                    $expiresAt = (new \DateTimeImmutable('+' . $config->magicLinkTtl . ' seconds'))
                        ->format('Y-m-d H:i:s');
                    $tokenRepo->insert((int) $user['id'], $pair['hash'], $expiresAt);

                    $link = $config->appUrl . '/login/verify?token=' . $pair['token'];
                    if ($returnTo !== '') {
                        $link .= '&r=' . rawurlencode($returnTo);
                    }

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
            })
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'magiclink:email',
                $emailRateLimit['limit'],
                $emailRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $parsed = $r->getParsedBody();
                    $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';
                    return $email !== '' ? $email : null;
                },
            ))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'magiclink:ip',
                $mlIpRateLimit['limit'],
                $mlIpRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $params = $r->getServerParams();
                    $ip     = $params['REMOTE_ADDR'] ?? null;
                    return is_string($ip) && $ip !== '' ? $ip : null;
                },
            ));

            // POST /logout — erhöht token_version (invalidiert alle Sessions) + löscht Cookie.
            // AuthZ: user (anon → 401); CSRF: mutierendes Verb → global erzwungen.
            $app->post('/logout', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($userRepo, $sessions, $audit): ResponseInterface {
                /** @var array<string, mixed>|null $user */
                $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
                if (is_array($user)) {
                    $userRepo->bumpTokenVersion((int) $user['id']);
                    $audit->log('user.logout', ['uid' => (int) $user['id']]);
                }
                return $sessions->clear($response->withStatus(302)->withHeader('Location', '/login'));
            })->add(AuthZMiddleware::user($responseFactory));

            // GET /login/verify?token=<klartext> — verifiziert den Magic-Link und
            // stellt eine frische Session aus (AuthZ: anon, GET → CSRF-exempt:
            // der Einmal-Token selbst ist die Capability). Bei Misserfolg KEIN
            // Side-Effect, einheitliche 4xx-Fehlerseite.
            $app->get('/login/verify', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($twig, $userRepo, $tokenRepo, $vault, $audit, $config, $sessions, $conn): MessageInterface {
                $params   = $request->getQueryParams();
                $token    = is_string($params['token'] ?? null) ? $params['token'] : '';
                $rawR     = is_string($params['r'] ?? null) ? $params['r'] : '';
                $returnTo = ReturnToValidator::isValid($rawR) ? $rawR : '/';

                $row = $token !== '' ? $tokenRepo->findActiveByHash($vault->hash($token)) : null;

                // Konstant-zeitige Bestätigung; Misserfolg → keine Mutation.
                if (!is_array($row) || !$vault->verify($token, (string) $row['token_hash'])) {
                    $audit->log('magic_link.verify_failed', []);
                    $response = $twig->render($response->withStatus(400), 'login-invalid.twig', []);
                    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
                }

                $userId    = (int) $row['user_id'];
                $tokenId   = (int) $row['id'];
                $isAdminML = false;

                // Atomar: Token verbrauchen + verified_at + Admin-Promotion.
                /** @var array<string, mixed> $user */
                $user = $conn->transactional(
                    function () use ($tokenRepo, $userRepo, $tokenId, $userId, $config, &$isAdminML): array {
                        $tokenRepo->markUsed($tokenId);
                        $userRepo->markVerified($userId);

                        $loaded = $userRepo->findById($userId);
                        if (!is_array($loaded)) {
                            // Sollte in einer Transaktion nicht passieren → fail-secure abbrechen.
                            throw new \RuntimeException('verify: user not found after markVerified');
                        }

                        if ($config->isAdminEmail((string) $loaded['email'])) {
                            $userRepo->promoteAdmin($userId);
                            $loaded['is_admin'] = 1;
                            $isAdminML          = true;
                        }

                        return $loaded;
                    },
                );

                $audit->log('magic_link.verified', ['email' => $user['email']]);
                if ($isAdminML) {
                    $audit->log('admin.promoted', ['email' => $user['email']]);
                }

                // Frische Session — etwaiges Vor-Login-Cookie wird ignoriert/ersetzt
                // (Session-Fixation-Schutz). Redirect-Ziel: validierter Return-To-Pfad
                // oder Default '/' (Issue 05: Open-Redirect-sicheres Deep-Linking).
                $response = $sessions->issue(
                    $response->withStatus(302)->withHeader('Location', $returnTo),
                    ['uid' => $userId, 'v' => (int) ($user['token_version'] ?? 0)],
                );

                return $response;
            })->add(AuthZMiddleware::anon($responseFactory));

            $ideaRepo      = new IdeaRepository($conn);
            $moderation    = new ContentModerationService($root . '/resources/moderation');
            $modConfigRepo = new ModerationConfigRepository($conn);
            $timeTrap      = new TimeTrapService($config->appKey);

            // GET /{board} — Board-Home = Ideenliste (Newest, Status-Filter, Pagination).
            // AuthZ: anon (Lesen ist öffentlich). Unbekannter Slug → 404.
            $app->get('/{board}', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($twig, $boardRepo, $ideaRepo): MessageInterface {
                $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write('Board not found.');
                    return $response->withStatus(404);
                }

                $params = $request->getQueryParams();

                // Status-Filter: Allow-List-Validierung; ungültig → null (alle anzeigen).
                $rawStatus    = is_string($params['status'] ?? null) ? $params['status'] : null;
                $activeStatus = ($rawStatus !== null && in_array($rawStatus, IdeaRepository::ALLOWED_STATUSES, true))
                    ? $rawStatus
                    : null;

                // Pagination: ?page= (1-basiert, konservative Seitengröße).
                $rawPage = isset($params['page']) ? (int) $params['page'] : 1;
                $page    = max(1, $rawPage);
                $limit   = IdeaRepository::DEFAULT_PAGE_SIZE;
                $offset  = ($page - 1) * $limit;

                $ideas = $ideaRepo->listByBoard((int) $board['id'], $activeStatus, $limit, $offset);

                // Gesamtzahl für Pagination (nur wenn nötig: Seite > 1 oder volle Seite).
                $totalPages = 1;
                if (count($ideas) === $limit || $page > 1) {
                    $total      = $ideaRepo->countByBoard((int) $board['id'], $activeStatus);
                    $totalPages = max(1, (int) ceil($total / $limit));
                }

                $isAuth = $request->getAttribute(AuthNMiddleware::ATTR_USER) !== null;

                $response = $twig->render($response, 'board/home.twig', [
                    'board_slug'     => $slug,
                    'board_name'     => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'board_intro'    => is_string($board['intro'] ?? null) ? $board['intro'] : '',
                    'ideas'          => $ideas,
                    'active_status'  => $activeStatus,
                    'page'           => $page,
                    'total_pages'    => $totalPages,
                    'is_authenticated' => $isAuth,
                ]);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            })->add(AuthZMiddleware::anon($responseFactory));

            // GET /{board}/ideas/{id} — Idee-Detailansicht (Sprint 3, Issue 04).
            // AuthZ: anon (Lesen ist öffentlich). Unbekannter Slug oder Idee → 404.
            // Cross-Board-Leak verhindert durch board-scopedes findInBoard().
            $app->get('/{board}/ideas/{id:[0-9]+}', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($twig, $boardRepo, $ideaRepo): MessageInterface {
                $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write('Board not found.');
                    return $response->withStatus(404);
                }

                $ideaId = (int) ($args['id'] ?? 0);
                $idea   = $ideaRepo->findInBoard((int) $board['id'], $ideaId);
                if (!is_array($idea)) {
                    $response->getBody()->write('Idea not found.');
                    return $response->withStatus(404);
                }

                $response = $twig->render($response, 'board/idea-detail.twig', [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'idea'       => $idea,
                ]);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            })->add(AuthZMiddleware::anon($responseFactory));

            // GET /{board}/ideas/new — Submit-Formular (Sprint 3, Issue 05).
            // AuthZ: anon; anon-Nutzer werden in der Action per Redirect zum Login geleitet
            // (Return-To auf die aktuelle URL gesetzt). Eingeloggte sehen das Formular.
            // POST-Route ist user-gated; das Formular selbst enthält keine Secrets.
            // Issue 09: Time-Trap-Stamp wird als Hidden-Field eingebettet.
            $app->get('/{board}/ideas/new', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($twig, $boardRepo, $timeTrap): MessageInterface {
                $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write('Board not found.');
                    return $response->withStatus(404);
                }

                // Anon → Redirect auf Login mit Return-To (Open-Redirect-sicher via rawurlencode).
                $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
                if (!is_array($user)) {
                    $returnTo = '/' . rawurlencode($slug) . '/ideas/new';
                    return $response
                        ->withStatus(302)
                        ->withHeader('Location', '/login?r=' . rawurlencode($returnTo));
                }

                $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
                $response  = $twig->render($response, 'board/idea-submit.twig', [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'values'     => ['title' => '', 'body' => ''],
                    'errors'     => [],
                    'time_trap'  => $timeTrap->stamp(),
                ]);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            })->add(AuthZMiddleware::anon($responseFactory));

            // POST /{board}/ideas — Idee anlegen (Sprint 3, Issue 05).
            // AuthZ: user (anon → 401); CSRF global erzwungen; per-Action-RateLimit idea:submit.
            $submitRateLimit = $config->rateLimit('idea:submit');
            $normalizer      = new TitleNormalizer();

            $app->post('/{board}/ideas', new IdeaCreateAction($twig, $boardRepo, $ideaRepo, $normalizer, $audit, $moderation, $timeTrap, $modConfigRepo))
            ->add(AuthZMiddleware::user($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'idea:submit',
                $submitRateLimit['limit'],
                $submitRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));

            // GET /{board}/ideas/{id}/edit — Edit-Formular (Sprint 3, Issue 06).
            // AuthZ: user; row-level Ownership-Check in der Action.
            // Issue 06: Time-Trap-Stamp wird als Hidden-Field eingebettet.
            $editAction = new IdeaEditAction(
                $twig,
                $boardRepo,
                $ideaRepo,
                $normalizer,
                $audit,
                $moderation,
                $timeTrap,
                $modConfigRepo,
            );

            // GET /edit: anon → Login-Redirect (in-action, wie submit GET).
            // Ownership-Check ebenfalls in der Action (404/403).
            $app->get('/{board}/ideas/{id:[0-9]+}/edit', $editAction->getEdit(...))
                ->add(AuthZMiddleware::anon($responseFactory));

            // POST /{board}/ideas/{id} — Idee aktualisieren (Sprint 3, Issue 06).
            // AuthZ: user; row-level Ownership-Check in der Action; CSRF global erzwungen.
            $app->post('/{board}/ideas/{id:[0-9]+}', $editAction->postEdit(...))
                ->add(AuthZMiddleware::user($responseFactory));

            // GET /admin/boards/{slug}/branding — Branding-Einstellseite (AuthZ: admin).
            // Rendert das Base-Layout mit dem (validierten) Branding des Boards selbst:
            // beweist den Konsum-Seam (Override greift / Default greift) observabel.
            $app->get('/admin/boards/{slug}/branding', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($twig, $boardRepo): MessageInterface {
                $slug  = is_string($args['slug'] ?? null) ? $args['slug'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write('Board not found.');
                    return $response->withStatus(404);
                }

                $primary   = is_string($board['primary_color'] ?? null) ? $board['primary_color'] : '';
                $secondary = is_string($board['secondary_color'] ?? null) ? $board['secondary_color'] : '';
                $logo      = is_string($board['logo_url'] ?? null) ? $board['logo_url'] : '';
                $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);

                $response = $twig->render($response, 'admin/board-branding.twig', [
                    'csrf_token'      => is_string($csrfToken) ? $csrfToken : '',
                    'board_slug'      => $slug,
                    'board_name'      => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'primary_color'   => $primary,
                    'secondary_color' => $secondary,
                    'logo_url'        => $logo,
                    'brand_style'     => BrandingValidator::inlineStyle(
                        $primary !== '' ? $primary : null,
                        $secondary !== '' ? $secondary : null,
                    ),
                    'brand_logo_url'  => $logo !== '' ? (BrandingValidator::logoUrl($logo) ?? '') : '',
                ]);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            })->add(AuthZMiddleware::admin($responseFactory));

            // POST /admin/boards/{slug}/branding — speichert das Branding (AuthZ: admin,
            // CSRF global erzwungen). Jeder Wert wird VOR Speicherung streng validiert;
            // ungültig → null → Default-Theme (kein roher Wert landet je in der DB/CSS).
            $app->post('/admin/boards/{slug}/branding', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($boardRepo, $audit): ResponseInterface {
                $slug  = is_string($args['slug'] ?? null) ? $args['slug'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write('Board not found.');
                    return $response->withStatus(404);
                }

                $parsed       = $request->getParsedBody();
                $rawPrimary   = is_array($parsed) ? (string) ($parsed['primary_color'] ?? '') : '';
                $rawSecondary = is_array($parsed) ? (string) ($parsed['secondary_color'] ?? '') : '';
                $rawLogo      = is_array($parsed) ? (string) ($parsed['logo_url'] ?? '') : '';

                $boardRepo->updateBranding(
                    (int) $board['id'],
                    $rawPrimary !== '' ? BrandingValidator::color($rawPrimary) : null,
                    $rawSecondary !== '' ? BrandingValidator::color($rawSecondary) : null,
                    $rawLogo !== '' ? BrandingValidator::logoUrl($rawLogo) : null,
                );

                $audit->log('board.branding_updated', ['board_id' => (int) $board['id']]);

                // Post/Redirect/Get: zurück auf die Einstellseite.
                return $response->withStatus(302)
                    ->withHeader('Location', '/admin/boards/' . rawurlencode($slug) . '/branding');
            })->add(AuthZMiddleware::admin($responseFactory));

            // GET /admin/boards/{slug}/moderation — Moderation-Einstellseite (AuthZ: admin).
            // Zeigt Toggle (an/aus) + aktuelle Board-Custom-Wörter.
            $app->get('/admin/boards/{slug}/moderation', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($twig, $boardRepo, $modConfigRepo): MessageInterface {
                $slug  = is_string($args['slug'] ?? null) ? $args['slug'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write('Board not found.');
                    return $response->withStatus(404);
                }

                $boardId   = (int) $board['id'];
                $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);

                $response = $twig->render($response, 'admin/board-moderation.twig', [
                    'csrf_token'         => is_string($csrfToken) ? $csrfToken : '',
                    'board_slug'         => $slug,
                    'board_name'         => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'moderation_enabled' => $modConfigRepo->isModerationEnabled($boardId),
                    'words'              => $modConfigRepo->listWords($boardId),
                ]);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            })->add(AuthZMiddleware::admin($responseFactory));

            // POST /admin/boards/{slug}/moderation — speichert Toggle + Wortlisten-Änderungen
            // (AuthZ: admin, CSRF global erzwungen). PRG: 302 zurück auf die Einstellseite.
            // Drei Sub-Aktionen via Hidden-Field "action": toggle | add | remove.
            // Ungültige Eingaben → Re-Render ohne 500 (kein Ausnahmen-Rethrow).
            $app->post('/admin/boards/{slug}/moderation', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($twig, $boardRepo, $modConfigRepo, $audit): MessageInterface {
                $slug  = is_string($args['slug'] ?? null) ? $args['slug'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write('Board not found.');
                    return $response->withStatus(404);
                }

                $boardId   = (int) $board['id'];
                $rawBody   = $request->getParsedBody();
                $fields    = is_array($rawBody) ? $rawBody : [];
                $action    = (string) ($fields['action'] ?? '');
                $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
                $wordError = '';

                if ($action === 'toggle') {
                    $enabled = isset($fields['moderation_enabled']) && $fields['moderation_enabled'] === '1';
                    $modConfigRepo->setModerationEnabled($boardId, $enabled);
                    $audit->log('board.moderation_toggle', ['board_id' => $boardId, 'enabled' => $enabled]);
                } elseif ($action === 'add') {
                    $rawWord = mb_substr(trim((string) ($fields['new_word'] ?? '')), 0, 200, 'UTF-8');

                    if ($rawWord === '') {
                        $wordError = 'Das Wort darf nicht leer sein.';
                    } elseif (mb_strlen($rawWord, 'UTF-8') > 200) {
                        $wordError = 'Das Wort darf maximal 200 Zeichen lang sein.';
                    } else {
                        $modConfigRepo->addWord($boardId, $rawWord);
                        $audit->log('board.moderation_word_added', ['board_id' => $boardId]);
                    }

                    // Bei Fehler: Re-Render ohne 500, Eingabe erhalten.
                    if ($wordError !== '') {
                        $response = $twig->render($response->withStatus(422), 'admin/board-moderation.twig', [
                            'csrf_token'         => is_string($csrfToken) ? $csrfToken : '',
                            'board_slug'         => $slug,
                            'board_name'         => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                            'moderation_enabled' => $modConfigRepo->isModerationEnabled($boardId),
                            'words'              => $modConfigRepo->listWords($boardId),
                            'new_word'           => $rawWord,
                            'word_error'         => $wordError,
                        ]);
                        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
                    }
                } elseif ($action === 'remove') {
                    $wordId = (int) ($fields['word_id'] ?? 0);
                    if ($wordId > 0) {
                        $modConfigRepo->removeWord($boardId, $wordId);
                        $audit->log('board.moderation_word_removed', ['board_id' => $boardId]);
                    }
                }

                // Post/Redirect/Get: zurück auf die Einstellseite.
                return $response->withStatus(302)
                    ->withHeader('Location', '/admin/boards/' . rawurlencode($slug) . '/moderation');
            })->add(AuthZMiddleware::admin($responseFactory));
        }

        return $app;
    }
}
