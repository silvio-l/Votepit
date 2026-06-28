<?php

declare(strict_types=1);

namespace Votepit\Http;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Action\IdeaCreateAction;
use Votepit\Http\Action\IdeaEditAction;
use Votepit\Http\Action\IdeaWithdrawAction;
use Votepit\Http\Action\VoteAction;
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
use Votepit\Persistence\SmtpSettingsRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\BrandingValidator;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
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
 * Sprint 4 (Issue 04): alle Routes liefern JSON-API-Antworten; Twig entfernt;
 * GET /api/bootstrap (CSRF-Token + Whoami für SPA).
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
        // Smoke-Route: beweist Boot + Pipeline + Security-Header.
        $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($audit): ResponseInterface {
            $audit->log('smoke.hit', ['ua' => $request->getHeaderLine('User-Agent')]);
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
            $response->getBody()->write((string) json_encode([
                'ok'               => true,
                'status'           => 'Security-Foundation aktiv.',
                'csrf_token'       => is_string($csrfToken) ? $csrfToken : '',
                'is_authenticated' => $user !== null,
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));

        // Login-Routen (Sprint 2): nur mit DB-Connection registrieren.
        if ($conn instanceof Connection) {
            $userRepo ??= new UserRepository($conn); // bereits oben gebaut; ??= narrowt den Typ
            $tokenRepo = new LoginTokenRepository($conn);
            $boardRepo = new BoardRepository($conn);
            $vault     = new TokenVault();
            $smtpSettingsRepo = new SmtpSettingsRepository($conn);
            $encryptionSvc    = new EncryptionService($config->appKey);
            $smtpFromDb       = $smtpSettingsRepo->findAsSmtpConfig($encryptionSvc);
            $resolvedMailer   = $mailer ?? new SymfonyMailerAdapter($smtpFromDb ?? $config->smtp);

            // Per-Action-Rate-Limits für Magic-Link-Anfragen (Issue 06).
            $emailRateLimit = $config->rateLimit('magiclink:email');
            $mlIpRateLimit  = $config->rateLimit('magiclink:ip');

            // GET /api/bootstrap — CSRF-Token + Whoami für SPA (AuthZ: anon).
            // Gibt dem SPA den aktuellen CSRF-Token und den eingeloggten Nutzer zurück.
            // Muss vom SPA beim Start aufgerufen werden, bevor mutierende Requests gesendet werden.
            $app->get('/api/bootstrap', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ): ResponseInterface {
                $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
                $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
                $userPayload = null;
                if (is_array($user)) {
                    $userPayload = [
                        'id'       => (int) ($user['id'] ?? 0),
                        'is_admin' => (bool) ($user['is_admin'] ?? false),
                    ];
                }
                $response->getBody()->write((string) json_encode([
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'user'       => $userPayload,
                ]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::anon($responseFactory));

            // GET /login — SPA-Route: liefert validierten return_to-Pfad (AuthZ: anon).
            $app->get('/login', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ): ResponseInterface {
                $params   = $request->getQueryParams();
                $rawR     = is_string($params['r'] ?? null) ? $params['r'] : '';
                $returnTo = ReturnToValidator::isValid($rawR) ? $rawR : '';
                $response->getBody()->write((string) json_encode([
                    'ok'        => true,
                    'return_to' => $returnTo,
                ]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::anon($responseFactory));

            // POST /login — verarbeitet die E-Mail, versendet den Magic-Link (AuthZ: anon).
            // Antwort ist IMMER identisch (Anti-Enumeration; AC 3 & 4).
            $app->post('/login', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($userRepo, $tokenRepo, $vault, $resolvedMailer, $audit, $config): ResponseInterface {
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

                $response->getBody()->write((string) json_encode(['ok' => true]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
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
                $response->getBody()->write((string) json_encode(['ok' => true]));
                return $sessions->clear(
                    $response->withStatus(200)->withHeader('Content-Type', 'application/json')
                );
            })->add(AuthZMiddleware::user($responseFactory));

            // GET /login/verify?token=<klartext> — verifiziert den Magic-Link und
            // stellt eine frische Session aus (AuthZ: anon, GET → CSRF-exempt:
            // der Einmal-Token selbst ist die Capability). Bei Misserfolg KEIN
            // Side-Effect, einheitliche 4xx-JSON-Fehlerantwort.
            $app->get('/login/verify', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($userRepo, $tokenRepo, $vault, $audit, $config, $sessions, $conn): ResponseInterface {
                $params   = $request->getQueryParams();
                $token    = is_string($params['token'] ?? null) ? $params['token'] : '';
                $rawR     = is_string($params['r'] ?? null) ? $params['r'] : '';
                $returnTo = ReturnToValidator::isValid($rawR) ? $rawR : '/';

                $row = $token !== '' ? $tokenRepo->findActiveByHash($vault->hash($token)) : null;

                // Konstant-zeitige Bestätigung; Misserfolg → keine Mutation.
                if (!is_array($row) || !$vault->verify($token, (string) $row['token_hash'])) {
                    $audit->log('magic_link.verify_failed', []);
                    $response->getBody()->write((string) json_encode([
                        'error' => [
                            'key'     => 'invalid_token',
                            'message' => 'Der Link ist ungültig oder abgelaufen.',
                        ],
                    ]));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
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
                // (Session-Fixation-Schutz). JSON-Antwort mit Redirect-Ziel für SPA.
                $response->getBody()->write((string) json_encode([
                    'ok'       => true,
                    'redirect' => $returnTo,
                ]));

                return $sessions->issue(
                    $response->withStatus(200)->withHeader('Content-Type', 'application/json'),
                    ['uid' => $userId, 'v' => (int) ($user['token_version'] ?? 0)],
                );
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
            ) use ($boardRepo, $ideaRepo): ResponseInterface {
                $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
                    ]));
                    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
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

                // Sortierachse: ?sort= gegen Allow-List validieren; unbekannt → DEFAULT_SORT.
                $rawSort    = is_string($params['sort'] ?? null) ? $params['sort'] : IdeaRepository::DEFAULT_SORT;
                $activeSort = array_key_exists($rawSort, IdeaRepository::SORT_AXES) ? $rawSort : IdeaRepository::DEFAULT_SORT;

                // Issue 02: eingeloggter User → my_vote je Idee via set-basierter Subquery.
                $currentUser   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
                $isAuth        = $currentUser !== null;
                $currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : null;

                $ideas = $ideaRepo->listByBoard((int) $board['id'], $activeStatus, $limit, $offset, $activeSort, $currentUserId);

                // Gesamtzahl für Pagination (nur wenn nötig: Seite > 1 oder volle Seite).
                $totalPages = 1;
                if (count($ideas) === $limit || $page > 1) {
                    $total      = $ideaRepo->countByBoard((int) $board['id'], $activeStatus);
                    $totalPages = max(1, (int) ceil($total / $limit));
                }

                // "Diese Woche"-Aggregate für die FeaturedIdeaCard (board-scoped).
                $stats = $ideaRepo->boardStats((int) $board['id']);

                $response->getBody()->write((string) json_encode([
                    'board'            => [
                        'id'    => (int) $board['id'],
                        'slug'  => $slug,
                        'name'  => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                        'intro' => is_string($board['intro'] ?? null) ? $board['intro'] : '',
                    ],
                    'ideas'            => $ideas,
                    'stats'            => $stats,
                    'active_status'    => $activeStatus,
                    'active_sort'      => $activeSort,
                    'page'             => $page,
                    'total_pages'      => $totalPages,
                    'is_authenticated' => $isAuth,
                ]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::anon($responseFactory));

            // GET /{board}/ideas/{id} — Idee-Detailansicht (Sprint 3, Issue 04).
            // AuthZ: anon (Lesen ist öffentlich). Unbekannter Slug oder Idee → 404.
            // Cross-Board-Leak verhindert durch board-scopedes findInBoard().
            $app->get('/{board}/ideas/{id:[0-9]+}', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($boardRepo, $ideaRepo): ResponseInterface {
                $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
                    ]));
                    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
                }

                $ideaId      = (int) ($args['id'] ?? 0);
                $currentUser = $request->getAttribute(AuthNMiddleware::ATTR_USER);
                // Issue 02: my_vote per set-basierter Subquery wenn eingeloggt.
                $currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : null;

                $idea = $ideaRepo->findInBoard((int) $board['id'], $ideaId, $currentUserId);
                if (!is_array($idea)) {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'not_found', 'message' => 'Idee nicht gefunden.'],
                    ]));
                    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
                }

                $response->getBody()->write((string) json_encode([
                    'board'            => [
                        'id'   => (int) $board['id'],
                        'slug' => $slug,
                        'name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    ],
                    'idea'             => $idea,
                    'is_authenticated' => $currentUser !== null,
                ]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::anon($responseFactory));

            // GET /{board}/ideas/new — SPA-Route: liefert Board-Info + Auth-Status + Time-Trap-Stamp
            // (AuthZ: anon). PRG-Redirect auf Login entfällt serverseitig; SPA wertet is_authenticated
            // aus. form_at must be echoed back by the SPA as the _form_at field in the POST.
            $app->get('/{board}/ideas/new', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($boardRepo, $timeTrap): ResponseInterface {
                $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
                    ]));
                    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
                }

                $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
                $response->getBody()->write((string) json_encode([
                    'board'            => [
                        'id'   => (int) $board['id'],
                        'slug' => $slug,
                        'name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    ],
                    'is_authenticated' => $user !== null,
                    'form_at'          => $timeTrap->stamp(),
                ]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::anon($responseFactory));

            // POST /{board}/ideas — Idee anlegen (Sprint 3, Issue 05).
            // AuthZ: user (anon → 401); CSRF global erzwungen; per-Action-RateLimit idea:submit.
            $submitRateLimit = $config->rateLimit('idea:submit');
            $normalizer      = new TitleNormalizer();

            $app->post('/{board}/ideas', new IdeaCreateAction($boardRepo, $ideaRepo, $normalizer, $audit, $moderation, $timeTrap, $modConfigRepo))
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
            // AuthZ: anon; row-level Ownership-Check in der Action; anon → 401 JSON.
            // Issue 06: Time-Trap-Stamp wird als Hidden-Field eingebettet.
            $editAction = new IdeaEditAction(
                $boardRepo,
                $ideaRepo,
                $normalizer,
                $audit,
                $moderation,
                $timeTrap,
                $modConfigRepo,
            );

            // GET /edit: anon → 401 JSON (in-action).
            // Ownership-Check ebenfalls in der Action (404/403).
            $app->get('/{board}/ideas/{id:[0-9]+}/edit', $editAction->getEdit(...))
                ->add(AuthZMiddleware::anon($responseFactory));

            // POST /{board}/ideas/{id} — Idee aktualisieren (Sprint 3, Issue 06).
            // AuthZ: user; row-level Ownership-Check in der Action; CSRF global erzwungen.
            $app->post('/{board}/ideas/{id:[0-9]+}', $editAction->postEdit(...))
                ->add(AuthZMiddleware::user($responseFactory));

            // POST /{board}/ideas/{id}/withdraw — Idee zurückziehen / Hard-Delete (Sprint 3, Issue 07).
            // AuthZ: user; row-level Ownership-Check in der Action; CSRF global erzwungen.
            $app->post('/{board}/ideas/{id:[0-9]+}/withdraw', new IdeaWithdrawAction($boardRepo, $ideaRepo, $audit))
                ->add(AuthZMiddleware::user($responseFactory));

            // POST /{board}/ideas/{id}/vote — Stimme abgeben/ändern/zurücknehmen (Sprint 4, Issue 01).
            // AuthZ: user (anon → 401); CSRF + BlockCheck global; per-Action-RateLimit idea:vote.
            // Board-Scoping in der Action via findInBoard (fremde Idee → 404, keine Zeile).
            $voteRepo      = new VoteRepository($conn);
            $voteRateLimit = $config->rateLimit('idea:vote');

            $app->post('/{board}/ideas/{id:[0-9]+}/vote', new VoteAction($boardRepo, $ideaRepo, $voteRepo, $audit))
            ->add(AuthZMiddleware::user($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'idea:vote',
                $voteRateLimit['limit'],
                $voteRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));

            // GET /admin/boards/{slug}/branding — Branding-Einstellseite (AuthZ: admin).
            // Gibt das (validierte) Branding des Boards als JSON zurück.
            $app->get('/admin/boards/{slug}/branding', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($boardRepo): ResponseInterface {
                $slug  = is_string($args['slug'] ?? null) ? $args['slug'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
                    ]));
                    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
                }

                $primary   = is_string($board['primary_color'] ?? null) ? $board['primary_color'] : '';
                $secondary = is_string($board['secondary_color'] ?? null) ? $board['secondary_color'] : '';
                $logo      = is_string($board['logo_url'] ?? null) ? $board['logo_url'] : '';

                // Gespeicherte Werte validieren — ungültig → null (Default-Theme).
                $sanitizedPrimary   = $primary !== '' ? BrandingValidator::color($primary) : null;
                $sanitizedSecondary = $secondary !== '' ? BrandingValidator::color($secondary) : null;
                $sanitizedLogo      = $logo !== '' ? BrandingValidator::logoUrl($logo) : null;

                $response->getBody()->write((string) json_encode([
                    'board_slug'      => $slug,
                    'board_name'      => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'primary_color'   => $sanitizedPrimary,
                    'secondary_color' => $sanitizedSecondary,
                    'logo_url'        => $sanitizedLogo,
                ]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
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
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
                    ]));
                    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
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

                $response->getBody()->write((string) json_encode(['ok' => true]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::admin($responseFactory));

            // GET /admin/boards/{slug}/moderation — Moderation-Einstellseite (AuthZ: admin).
            // Zeigt Toggle (an/aus) + aktuelle Board-Custom-Wörter als JSON.
            $app->get('/admin/boards/{slug}/moderation', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($boardRepo, $modConfigRepo): ResponseInterface {
                $slug  = is_string($args['slug'] ?? null) ? $args['slug'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
                    ]));
                    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
                }

                $boardId = (int) $board['id'];

                $response->getBody()->write((string) json_encode([
                    'board_slug'         => $slug,
                    'board_name'         => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'moderation_enabled' => $modConfigRepo->isModerationEnabled($boardId),
                    'words'              => $modConfigRepo->listWords($boardId),
                ]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::admin($responseFactory));

            // POST /admin/boards/{slug}/moderation — speichert Toggle + Wortlisten-Änderungen
            // (AuthZ: admin, CSRF global erzwungen). JSON-Antwort: 200 ok | 422 error.
            // Drei Sub-Aktionen via Hidden-Field "action": toggle | add | remove.
            // Ungültige Eingaben → 422 JSON ohne 500 (kein Ausnahmen-Rethrow).
            $app->post('/admin/boards/{slug}/moderation', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args,
            ) use ($boardRepo, $modConfigRepo, $audit): ResponseInterface {
                $slug  = is_string($args['slug'] ?? null) ? $args['slug'] : '';
                $board = $boardRepo->findBySlug($slug);
                if (!is_array($board)) {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
                    ]));
                    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
                }

                $boardId  = (int) $board['id'];
                $rawBody  = $request->getParsedBody();
                $fields   = is_array($rawBody) ? $rawBody : [];
                $action   = (string) ($fields['action'] ?? '');

                if ($action === 'toggle') {
                    $enabled = isset($fields['moderation_enabled']) && $fields['moderation_enabled'] === '1';
                    $modConfigRepo->setModerationEnabled($boardId, $enabled);
                    $audit->log('board.moderation_toggle', ['board_id' => $boardId, 'enabled' => $enabled]);
                } elseif ($action === 'add') {
                    $rawWord = mb_substr(trim((string) ($fields['new_word'] ?? '')), 0, 200, 'UTF-8');

                    if ($rawWord === '') {
                        $response->getBody()->write((string) json_encode([
                            'error' => [
                                'key'     => 'validation_error',
                                'message' => 'Validation failed.',
                                'fields'  => ['new_word' => 'Das Wort darf nicht leer sein.'],
                            ],
                        ]));
                        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
                    }

                    if (mb_strlen($rawWord, 'UTF-8') > 200) {
                        $response->getBody()->write((string) json_encode([
                            'error' => [
                                'key'     => 'validation_error',
                                'message' => 'Validation failed.',
                                'fields'  => ['new_word' => 'Das Wort darf maximal 200 Zeichen lang sein.'],
                            ],
                        ]));
                        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
                    }

                    $modConfigRepo->addWord($boardId, $rawWord);
                    $audit->log('board.moderation_word_added', ['board_id' => $boardId]);
                } elseif ($action === 'remove') {
                    $wordId = (int) ($fields['word_id'] ?? 0);
                    if ($wordId > 0) {
                        $modConfigRepo->removeWord($boardId, $wordId);
                        $audit->log('board.moderation_word_removed', ['board_id' => $boardId]);
                    }
                }

                $response->getBody()->write((string) json_encode(['ok' => true]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::admin($responseFactory));

            // GET /admin/smtp — liest SMTP-Settings (AuthZ: admin). Passwort NICHT zurückgeben.
            $app->get('/admin/smtp', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($smtpSettingsRepo): ResponseInterface {
                $settings = $smtpSettingsRepo->find();

                $response->getBody()->write((string) json_encode([
                    'host'         => $settings['smtp.host'] ?? '',
                    'port'         => (int) ($settings['smtp.port'] ?? 587),
                    'user'         => $settings['smtp.user'] ?? '',
                    'encryption'   => $settings['smtp.encryption'] ?? 'tls',
                    'from_email'   => $settings['smtp.from_email'] ?? '',
                    'from_name'    => $settings['smtp.from_name'] ?? '',
                    'password_set' => isset($settings['smtp.pass']) && $settings['smtp.pass'] !== '',
                ]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::admin($responseFactory));

            // PUT /admin/smtp — speichert SMTP-Settings (AuthZ: admin, CSRF erzwungen).
            // Leeres password-Feld = unverändert lassen (keep existing).
            $app->put('/admin/smtp', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($smtpSettingsRepo, $encryptionSvc, $audit): ResponseInterface {
                $rawBody = $request->getParsedBody();
                $fields  = is_array($rawBody) ? $rawBody : [];

                $host       = trim((string) ($fields['host'] ?? ''));
                $port       = (int) ($fields['port'] ?? 587);
                $user       = trim((string) ($fields['user'] ?? ''));
                $encryption = (string) ($fields['encryption'] ?? 'tls');
                $fromEmail  = trim((string) ($fields['from_email'] ?? ''));
                $fromName   = trim((string) ($fields['from_name'] ?? 'Votepit'));
                $password   = (string) ($fields['password'] ?? '');

                // Validation.
                $errors = [];
                if ($host === '') {
                    $errors['host'] = 'Host darf nicht leer sein.';
                }
                if ($port < 1 || $port > 65535) {
                    $errors['port'] = 'Port muss zwischen 1 und 65535 liegen.';
                }
                if (!in_array($encryption, ['tls', 'ssl', ''], true)) {
                    $errors['encryption'] = 'Verschlüsselung muss "tls", "ssl" oder "" sein.';
                }
                if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
                    $errors['from_email'] = 'Absender-E-Mail fehlt oder ist ungültig.';
                }

                if ($errors !== []) {
                    $response->getBody()->write((string) json_encode([
                        'error' => [
                            'key'     => 'validation_error',
                            'message' => 'Validation failed.',
                            'fields'  => $errors,
                        ],
                    ]));
                    return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
                }

                $encryptedPass = $password !== '' ? $encryptionSvc->encrypt($password) : null;

                $smtpSettingsRepo->save($host, $port, $user, $encryption, $fromEmail, $fromName, $encryptedPass);
                $audit->log('smtp.settings_updated', []);

                $response->getBody()->write((string) json_encode(['ok' => true]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(AuthZMiddleware::admin($responseFactory));

            // POST /admin/smtp/test — sendet eine Test-Mail mit den übergebenen ODER gespeicherten
            // Settings (AuthZ: admin, CSRF erzwungen, Rate-Limit).
            $smtpTestLimit = $config->rateLimit('smtp:test');
            $app->post('/admin/smtp/test', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
            ) use ($smtpSettingsRepo, $encryptionSvc, $config): ResponseInterface {
                $rawBody = $request->getParsedBody();
                $fields  = is_array($rawBody) ? $rawBody : [];

                // Admin-E-Mail aus Session-User.
                $user    = $request->getAttribute(AuthNMiddleware::ATTR_USER);
                $toEmail = is_array($user) ? (string) ($user['email'] ?? '') : '';

                if ($toEmail === '') {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'no_recipient', 'message' => 'Kein Empfänger ermittelbar.'],
                    ]));
                    return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
                }

                // Übergebene Settings vs. gespeicherte Settings.
                $host      = trim((string) ($fields['host'] ?? ''));
                $useInline = $host !== '';

                if ($useInline) {
                    $port       = (int) ($fields['port'] ?? 587);
                    $user2      = trim((string) ($fields['user'] ?? ''));
                    $encryption = (string) ($fields['encryption'] ?? 'tls');
                    $fromEmail  = trim((string) ($fields['from_email'] ?? ''));
                    $fromName   = trim((string) ($fields['from_name'] ?? 'Votepit'));
                    $password   = (string) ($fields['password'] ?? '');

                    // Falls password leer → aus DB nachladen.
                    if ($password === '') {
                        $stored = $smtpSettingsRepo->find();
                        $encPw  = $stored['smtp.pass'] ?? '';
                        $password = ($encPw !== '') ? ($encryptionSvc->decrypt($encPw) ?? '') : '';
                    }

                    try {
                        $smtpConfig = \Votepit\SmtpConfig::fromArray([
                            'host'       => $host,
                            'port'       => $port,
                            'user'       => $user2,
                            'pass'       => $password,
                            'encryption' => $encryption,
                            'from_email' => $fromEmail !== '' ? $fromEmail : 'noreply@example.com',
                            'from_name'  => $fromName,
                        ]);
                    } catch (\Votepit\ConfigException $e) {
                        $response->getBody()->write((string) json_encode([
                            'error' => ['key' => 'config_error', 'message' => $e->getMessage()],
                        ]));
                        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
                    }
                } else {
                    // Gespeicherte DB-Settings.
                    $smtpConfig = $smtpSettingsRepo->findAsSmtpConfig($encryptionSvc) ?? $config->smtp;
                }

                try {
                    $testMailer = new \Votepit\Mail\SymfonyMailerAdapter($smtpConfig);
                    $testMailer->send(
                        $toEmail,
                        'Votepit SMTP-Test',
                        "Dies ist eine Votepit-Test-E-Mail.\n\nWenn du diese Nachricht siehst, funktioniert deine SMTP-Konfiguration.\n",
                    );
                } catch (\Throwable $e) {
                    $response->getBody()->write((string) json_encode([
                        'error' => ['key' => 'send_failed', 'message' => $e->getMessage()],
                    ]));
                    return $response->withStatus(502)->withHeader('Content-Type', 'application/json');
                }

                $response->getBody()->write((string) json_encode(['ok' => true, 'recipient' => $toEmail]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            })->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'smtp:test',
                $smtpTestLimit['limit'],
                $smtpTestLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $u = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($u) ? (string) ($u['id'] ?? '') : null;
                },
            ))->add(AuthZMiddleware::admin($responseFactory));
        }

        return $app;
    }
}
