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
 * GET /{board}/ideas/{id}/edit  — Edit-Formular (AuthZ: user + Ownership).
 * POST /{board}/ideas/{id}      — Idee aktualisieren (AuthZ: user + Ownership, CSRF erzwungen).
 *
 * Ownership-Check in der Action (nicht im Pipeline-Guard):
 *   - Idee nicht im Board → 404
 *   - Idee vorhanden aber anderer Autor → 403
 *   - Anonym → AuthZMiddleware::user() leitet vorher zum Login um
 *
 * Moderation + Bot-Abwehr: gleicher Vertrag wie IdeaCreateAction (Issue 09/10).
 * Honeypot + Time-Trap aktiv unabhängig vom Board-Toggle.
 * Wortfilter: nur wenn Board-Toggle an (wie beim Submit).
 */
final readonly class IdeaEditAction
{
    /** Honeypot form field name — must match idea-edit.twig. */
    public const HONEYPOT_FIELD = 'website';

    /** Time-Trap form field name — must match idea-edit.twig. */
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
    ): MessageInterface {
        $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
        $board = $this->boardRepo->findBySlug($slug);
        if (!is_array($board)) {
            $response->getBody()->write('Board not found.');
            return $response->withStatus(404);
        }

        $ideaId = (int) ($args['id'] ?? 0);
        $idea   = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write('Idea not found.');
            return $response->withStatus(404);
        }

        // Anon → Redirect auf Login mit Return-To (Open-Redirect-sicher).
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if (!is_array($user)) {
            $returnTo = '/' . rawurlencode($slug) . '/ideas/' . $ideaId . '/edit';
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/login?r=' . rawurlencode($returnTo));
        }

        /** @var array<string, mixed> $user */
        if ((int) ($idea['author_id'] ?? -1) !== (int) ($user['id'] ?? 0)) {
            $response->getBody()->write('Forbidden.');
            return $response->withStatus(403);
        }

        $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
        $response  = $this->twig->render($response, 'board/idea-edit.twig', [
            'board_slug' => $slug,
            'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            'idea'       => $idea,
            'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
            'values'     => ['title' => (string) ($idea['title'] ?? ''), 'body' => (string) ($idea['body'] ?? '')],
            'errors'     => [],
            'time_trap'  => $this->timeTrap->stamp(),
        ]);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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
    ): MessageInterface {
        $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
        $board = $this->boardRepo->findBySlug($slug);
        if (!is_array($board)) {
            $response->getBody()->write('Board not found.');
            return $response->withStatus(404);
        }

        $ideaId = (int) ($args['id'] ?? 0);
        $idea   = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write('Idea not found.');
            return $response->withStatus(404);
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if ((int) ($idea['author_id'] ?? -1) !== (int) ($user['id'] ?? 0)) {
            $response->getBody()->write('Forbidden.');
            return $response->withStatus(403);
        }

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
                'board/idea-edit.twig',
                [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'idea'       => $idea,
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
                'board/idea-edit.twig',
                [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'idea'       => $idea,
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'values'     => ['title' => $rawTitle, 'body' => $rawBody],
                    'errors'     => [],
                    'time_trap'  => $this->timeTrap->stamp(),
                ],
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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

        $errors = [];
        foreach ($titleErrors as $e) {
            $errors['title'][] = $e->getMessage();
        }
        foreach ($bodyErrors as $e) {
            $errors['body'][] = $e->getMessage();
        }

        if ($errors !== []) {
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $response  = $this->twig->render(
                $response->withStatus(422),
                'board/idea-edit.twig',
                [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'idea'       => $idea,
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'values'     => ['title' => $rawTitle, 'body' => $rawBody],
                    'errors'     => $errors,
                    'time_trap'  => $this->timeTrap->stamp(),
                ],
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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
                'board_id' => $boardId,
                'hit_count' => count($modResult['hits']),
            ]);

            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $response  = $this->twig->render(
                $response->withStatus(422),
                'board/idea-edit.twig',
                [
                    'board_slug' => $slug,
                    'board_name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                    'idea'       => $idea,
                    'csrf_token' => is_string($csrfToken) ? $csrfToken : '',
                    'values'     => ['title' => $rawTitle, 'body' => $rawBody],
                    'errors'     => ['moderation' => ['Dein Text enthält unzulässige Begriffe. Bitte formuliere ihn um.']],
                    'time_trap'  => $this->timeTrap->stamp(),
                ],
            );
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // Normalisierung + Update (board-scoped, author-scoped, Prepared-Statement).
        $titleNormalized = $this->normalizer->normalize($rawTitle);
        $authorId        = (int) ($user['id'] ?? 0);

        $this->ideaRepo->updateOwn($ideaId, $authorId, $boardId, $rawTitle, $titleNormalized, $rawBody);

        $this->audit->log('idea.updated', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        // Post/Redirect/Get — Reload löst kein Doppel-Submit aus.
        return $response
            ->withStatus(302)
            ->withHeader('Location', '/' . rawurlencode($slug) . '/ideas/' . $ideaId);
    }
}
