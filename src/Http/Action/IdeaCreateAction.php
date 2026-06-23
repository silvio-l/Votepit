<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\CsrfMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Security\TimeTrapService;

/**
 * POST /{board}/ideas — Idee anlegen (Sprint 3, Issue 05).
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory).
 * CSRF: global erzwungen (CsrfMiddleware im POST-Pfad).
 * RateLimit `idea:submit`: per-Action-RateLimit (in AppFactory angehängt).
 *
 * Validierung: Titel 3..200 Zeichen, Body min. 10 Zeichen — Symfony Validator.
 * Bei Fehler → 422 + Form re-render mit erhaltenen Eingabewerten und Fehlern.
 * Erfolg → 302 auf /{board}/ideas/{id} (Post/Redirect/Get).
 *
 * Issue 09: Moderation-Hard-Block, Honeypot, Time-Trap.
 */
final readonly class IdeaCreateAction
{
    /** Honeypot form field name — must match idea-submit.twig. */
    public const HONEYPOT_FIELD = 'website';

    /** Time-Trap form field name — must match idea-submit.twig. */
    public const TIME_TRAP_FIELD = '_form_at';

    public function __construct(
        private Twig $twig,
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private TitleNormalizer $normalizer,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
        private TimeTrapService $timeTrap,
        private ?ModerationConfigRepository $moderationConfigRepo = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): MessageInterface {
        $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
        $board = $this->boardRepo->findBySlug($slug);
        if (!is_array($board)) {
            $response->getBody()->write('Board not found.');
            return $response->withStatus(404);
        }

        // Eingeloggter User — AuthZMiddleware::user() sichert, dass er da ist.
        /** @var array<string, mixed> $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        $parsed = $request->getParsedBody();
        $rawTitle    = is_array($parsed) ? trim((string) ($parsed['title'] ?? '')) : '';
        $rawBody     = is_array($parsed) ? trim((string) ($parsed['body'] ?? '')) : '';
        $honeypot    = is_array($parsed) ? (string) ($parsed[self::HONEYPOT_FIELD] ?? '') : '';
        $timeTrapVal = is_array($parsed) ? (string) ($parsed[self::TIME_TRAP_FIELD] ?? '') : '';

        // Bot-Abwehr 1: Honeypot-Feld — befüllt → stille Ablehnung (422 ohne Hinweis).
        if ($honeypot !== '') {
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $response  = $this->twig->render(
                $response->withStatus(422),
                'board/idea-submit.twig',
                [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'values'     => ['title' => $rawTitle, 'body' => $rawBody],
                    'errors'     => [],
                    'time_trap'  => $this->timeTrap->stamp(),
                ],
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // Bot-Abwehr 2: Time-Trap — zu schnell → stille Ablehnung (422 ohne Hinweis).
        if (!$this->timeTrap->verify($timeTrapVal)) {
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $response  = $this->twig->render(
                $response->withStatus(422),
                'board/idea-submit.twig',
                [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'values'     => ['title' => $rawTitle, 'body' => $rawBody],
                    'errors'     => [],
                    'time_trap'  => $this->timeTrap->stamp(),
                ],
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // Validierung via Symfony Validator (bereits Dependency).
        $validator   = Validation::createValidator();
        $titleErrors = $validator->validate($rawTitle, [
            new Assert\NotBlank(message: 'Der Titel darf nicht leer sein.'),
            new Assert\Length(
                min: 3,
                max: 200,
                minMessage: 'Der Titel muss mindestens {{ limit }} Zeichen lang sein.',
                maxMessage: 'Der Titel darf maximal {{ limit }} Zeichen lang sein.',
            ),
        ]);
        $bodyErrors  = $validator->validate($rawBody, [
            new Assert\NotBlank(message: 'Die Beschreibung darf nicht leer sein.'),
            new Assert\Length(
                min: 10,
                minMessage: 'Die Beschreibung muss mindestens {{ limit }} Zeichen lang sein.',
            ),
        ]);

        $errors = [];
        foreach ($titleErrors as $e) {
            $errors['title'][] = $e->getMessage();
        }
        foreach ($bodyErrors as $e) {
            $errors['body'][] = $e->getMessage();
        }

        if ($errors !== []) {
            // Re-render: 422 + Formular mit Fehlern + erhaltene Eingabewerte.
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $response  = $this->twig->render(
                $response->withStatus(422),
                'board/idea-submit.twig',
                [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'values'     => ['title' => $rawTitle, 'body' => $rawBody],
                    'errors'     => $errors,
                    'time_trap'  => $this->timeTrap->stamp(),
                ],
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // Moderation-Hard-Block: nach Struktur-Validierung, vor DB-Eintrag.
        // Per-Board-Toggle: bei „aus" wird nur der Wortfilter übersprungen — Honeypot,
        // Time-Trap, CSRF und Rate-Limit laufen immer (bereits oben ausgeführt).
        $boardId = (int) $board['id'];
        $moderationEnabled = !$this->moderationConfigRepo instanceof \Votepit\Persistence\ModerationConfigRepository
            || $this->moderationConfigRepo->isModerationEnabled($boardId);

        $effectiveModeration = $this->moderation;
        if ($moderationEnabled && $this->moderationConfigRepo instanceof \Votepit\Persistence\ModerationConfigRepository) {
            $customWords = $this->moderationConfigRepo->wordList($boardId);
            if ($customWords !== []) {
                $effectiveModeration = $this->moderation->withAdditionalWords($customWords);
            }
        }

        $modResult = $moderationEnabled ? $effectiveModeration->check($rawTitle, $rawBody) : ['clean' => true, 'hits' => []];
        if (!$modResult['clean']) {
            // Maskiert loggen — rohe Treffer dürfen nie ins Log.
            $this->audit->log('idea.moderation_blocked', [
                'board_id' => (int) $board['id'],
                'hit_count' => count($modResult['hits']),
            ]);

            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $response  = $this->twig->render(
                $response->withStatus(422),
                'board/idea-submit.twig',
                [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'values'     => ['title' => $rawTitle, 'body' => $rawBody],
                    'errors'     => ['moderation' => ['Dein Text enthält unzulässige Begriffe. Bitte formuliere ihn um.']],
                    'time_trap'  => $this->timeTrap->stamp(),
                ],
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // Normalisierung (TitleNormalizer, Sprint 5-kompatibler Key).
        $titleNormalized = $this->normalizer->normalize($rawTitle);

        // Anlegen board-scoped (Prepared-Statement via IdeaRepository).
        $authorId = (int) ($user['id'] ?? 0);

        $ideaId = $this->ideaRepo->create($boardId, $authorId, $rawTitle, $titleNormalized, $rawBody);

        $this->audit->log('idea.created', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        // Post/Redirect/Get — Reload löst kein Doppel-Submit aus.
        return $response
            ->withStatus(302)
            ->withHeader('Location', '/' . rawurlencode($slug) . '/ideas/' . $ideaId);
    }
}
