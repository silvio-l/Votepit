<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Middleware\AuthNMiddleware;
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
 * Bei Fehler → 422 + einheitlicher JSON-Fehlerkontrakt.
 * Erfolg → 201 JSON `{"ok": true, "id": N}`.
 *
 * Issue 09: Moderation-Hard-Block, Honeypot, Time-Trap.
 */
final readonly class IdeaCreateAction
{
    /** Honeypot form field name. */
    public const HONEYPOT_FIELD = 'website';

    /** Time-Trap form field name. */
    public const TIME_TRAP_FIELD = '_form_at';

    public function __construct(
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
    ): ResponseInterface {
        $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
        $board = $this->boardRepo->findBySlug($slug);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
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
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'rejected', 'message' => 'Die Anfrage wurde abgelehnt.'],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Bot-Abwehr 2: Time-Trap — zu schnell → stille Ablehnung (422 ohne Hinweis).
        if (!$this->timeTrap->verify($timeTrapVal)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'rejected', 'message' => 'Die Anfrage wurde abgelehnt.'],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
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

        /** @var array<string, string> $fields */
        $fields = [];
        foreach ($titleErrors as $e) {
            $fields['title'] = (string) $e->getMessage();
            break;
        }
        foreach ($bodyErrors as $e) {
            $fields['body'] = (string) $e->getMessage();
            break;
        }

        if ($fields !== []) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => $fields,
                    'values'  => ['title' => $rawTitle, 'body' => $rawBody],
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
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
                'board_id'  => (int) $board['id'],
                'hit_count' => count($modResult['hits']),
            ]);

            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'moderation_blocked',
                    'message' => 'Dein Text enthält unzulässige Begriffe. Bitte formuliere ihn um.',
                    'fields'  => [],
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Normalisierung (TitleNormalizer, Sprint 5-kompatibler Key).
        $titleNormalized = $this->normalizer->normalize($rawTitle);

        // Anlegen board-scoped (Prepared-Statement via IdeaRepository).
        $authorId = (int) ($user['id'] ?? 0);

        $ideaId = $this->ideaRepo->create($boardId, $authorId, $rawTitle, $titleNormalized, $rawBody);

        $this->audit->log('idea.created', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $ideaId]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
