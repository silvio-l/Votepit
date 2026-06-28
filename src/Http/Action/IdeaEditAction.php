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
 * GET /{board}/ideas/{id}/edit  — Edit-Formular (AuthZ: anon + Ownership, anon → 401 JSON).
 * POST /{board}/ideas/{id}      — Idee aktualisieren (AuthZ: user + Ownership, CSRF erzwungen).
 *
 * Ownership-Check in der Action (nicht im Pipeline-Guard):
 *   - Idee nicht im Board → 404
 *   - Idee vorhanden aber anderer Autor → 403
 *   - Anonym → 401 JSON (in-action, kein PRG-Redirect mehr)
 *
 * Moderation + Bot-Abwehr: gleicher Vertrag wie IdeaCreateAction (Issue 09/10).
 * Honeypot + Time-Trap aktiv unabhängig vom Board-Toggle.
 * Wortfilter: nur wenn Board-Toggle an (wie beim Submit).
 */
final readonly class IdeaEditAction
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

    // -------------------------------------------------------------------------
    // GET /{board}/ideas/{id}/edit
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     */
    public function getEdit(
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

        $ideaId = (int) ($args['id'] ?? 0);
        $idea   = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idee nicht gefunden.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Anon → 401 JSON (SPA leitet zum Login weiter).
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if (!is_array($user)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'unauthenticated', 'message' => 'Login erforderlich.'],
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        if ((int) ($idea['author_id'] ?? -1) !== (int) ($user['id'] ?? 0)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'forbidden', 'message' => 'Zugriff verweigert.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode([
            'board'            => [
                'id'   => (int) $board['id'],
                'slug' => $slug,
                'name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            ],
            'idea'             => $idea,
            'is_authenticated' => true,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    // -------------------------------------------------------------------------
    // POST /{board}/ideas/{id}
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     */
    public function postEdit(
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

        $ideaId = (int) ($args['id'] ?? 0);
        $idea   = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idee nicht gefunden.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if ((int) ($idea['author_id'] ?? -1) !== (int) ($user['id'] ?? 0)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'forbidden', 'message' => 'Zugriff verweigert.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

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

        // Validierung via Symfony Validator.
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

        // Moderation-Hard-Block: nach Struktur-Validierung, vor DB-Update.
        $boardId = (int) $board['id'];
        $moderationEnabled = !$this->moderationConfigRepo instanceof ModerationConfigRepository
            || $this->moderationConfigRepo->isModerationEnabled($boardId);

        $effectiveModeration = $this->moderation;
        if ($moderationEnabled && $this->moderationConfigRepo instanceof ModerationConfigRepository) {
            $customWords = $this->moderationConfigRepo->wordList($boardId);
            if ($customWords !== []) {
                $effectiveModeration = $this->moderation->withAdditionalWords($customWords);
            }
        }

        $modResult = $moderationEnabled ? $effectiveModeration->check($rawTitle, $rawBody) : ['clean' => true, 'hits' => []];
        if (!$modResult['clean']) {
            $this->audit->log('idea.moderation_blocked', [
                'board_id'  => $boardId,
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

        // Normalisierung + Update (board-scoped, author-scoped, Prepared-Statement).
        $titleNormalized = $this->normalizer->normalize($rawTitle);
        $authorId        = (int) ($user['id'] ?? 0);

        $this->ideaRepo->updateOwn($ideaId, $authorId, $boardId, $rawTitle, $titleNormalized, $rawBody);

        $this->audit->log('idea.updated', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
