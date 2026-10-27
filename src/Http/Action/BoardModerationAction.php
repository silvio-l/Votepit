<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\Premoderation;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;

/**
 * GET  /admin/boards/{slug}/moderation — moderation settings page (AuthZ: admin).
 * Shows the toggle (on/off) + current board custom words as JSON.
 *
 * POST /admin/boards/{slug}/moderation — saves toggle + word-list changes
 * (AuthZ: admin, CSRF globally enforced). JSON response: 200 ok | 422 error.
 * Sub-actions via hidden field "action": toggle | add | bulk_add | remove |
 * premoderation (issue 05: sets boards.premoderation from the
 * Domain\Premoderation::MODES allow-list) | anonymous_submission (ADR 0006
 * §8/§9: guest idea submission switch — plan gate + Turnstile required to
 * switch on, raises premoderation `off` to `anonymous`; turning
 * premoderation `off` while the switch is on needs `confirm_guest_public`).
 * Invalid input → 422 JSON without 500 (no exception rethrow).
 */
final readonly class BoardModerationAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private ModerationConfigRepository $modConfigRepo,
        private ContentModerationService $moderation,
        private AuditLogger $audit,
        private IdeaRepository $ideaRepo,
        private ?AnonymousSubmissionGate $anonymousSubmissionGate = null,
    ) {}

    /** Max words accepted per bulk-add submission (abuse guard, not a product limit). */
    private const MAX_BULK_WORDS = 500;

    // -------------------------------------------------------------------------
    // GET /admin/boards/{slug}/moderation
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function getModeration(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $boardId = (int) $board['id'];

        $response->getBody()->write((string) json_encode([
            'board_slug'         => $slug,
            'board_name'         => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            'moderation_enabled' => $this->modConfigRepo->isModerationEnabled($boardId),
            'words'              => $this->modConfigRepo->listWords($boardId),
            'base_lists'         => $this->moderation->baseListSizes(),
            // Pre-moderation (issue 05): current mode (fail-secure normalized)
            // + the queue's pending-count badge.
            'premoderation'      => Premoderation::normalizeMode($board['premoderation'] ?? null),
            'pending_count'      => $this->ideaRepo->countPendingByBoard($boardId),
            // Guest idea submission (ADR 0006): the switch plus the two
            // preconditions the admin UI explains when it cannot be used.
            'anonymous_submission' => [
                'enabled'              => (bool) ($board['anonymous_submission_enabled'] ?? false),
                'instance_allowed'     => $this->anonymousSubmissionGate?->instanceAllows() === true,
                'turnstile_configured' => $this->anonymousSubmissionGate?->turnstileConfigured() === true,
                'plan_allowed'         => $this->anonymousSubmissionGate?->planAllows($accountId) === true,
            ],
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    // -------------------------------------------------------------------------
    // POST /admin/boards/{slug}/moderation
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function postModeration(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId  = (int) $board['id'];
        $rawBody  = $request->getParsedBody();
        $fields   = is_array($rawBody) ? $rawBody : [];
        $action   = (string) ($fields['action'] ?? '');

        if ($action === 'premoderation') {
            // Pre-moderation (issue 05): closed allow-list, anything else → 422
            // and the stored mode stays untouched.
            $mode = (string) ($fields['premoderation'] ?? '');
            if (!in_array($mode, Premoderation::MODES, true)) {
                $response->getBody()->write((string) json_encode([
                    'error' => [
                        'key'     => 'validation_error',
                        'message' => 'Validation failed.',
                        'fields'  => ['premoderation' => 'Please choose a valid option.'],
                    ],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            // Guest ideas going public unreviewed needs an explicit opt-in
            // (ADR 0006 §8) — the SPA's confirm dialog sends the flag.
            $guestsOn = (bool) ($board['anonymous_submission_enabled'] ?? false);
            if ($mode === Premoderation::MODE_OFF && $guestsOn && !$this->truthy($fields['confirm_guest_public'] ?? null)) {
                return JsonError::respond($response, 422, 'confirmation_required', 'Confirm that ideas submitted without an account become public immediately.');
            }

            $this->boardRepo->updatePremoderation($boardId, $accountId, $mode);
            $this->audit->log('board.premoderation_changed', ['board_id' => $boardId, 'mode' => $mode]);
            if ($mode === Premoderation::MODE_OFF && $guestsOn) {
                $this->audit->log('board.anon_submission.premoderation_disabled', [
                    'board_id' => $boardId,
                    'actor'    => $this->actorId($request),
                ]);
            }
        } elseif ($action === 'anonymous_submission') {
            $enable = $this->truthy($fields['anonymous_submission_enabled'] ?? null);
            if ($enable) {
                $gate = $this->anonymousSubmissionGate;
                if (!$gate instanceof AnonymousSubmissionGate || !$gate->instanceAllows()) {
                    return JsonError::respond($response, 422, 'anonymous_submission_unavailable', 'Submissions without an account are not available on this installation.');
                }
                if (!$gate->planAllows($accountId)) {
                    return JsonError::respond($response, 422, 'plan_limit_anonymous_submission', 'Your plan does not include submissions without an account.');
                }
                if (!$gate->turnstileConfigured()) {
                    return JsonError::respond($response, 422, 'captcha_unavailable', 'Submissions without an account need Cloudflare Turnstile to be configured.');
                }

                // Every switch-on re-establishes the review floor; an earlier
                // "off" confirmation is never silently reused.
                if (Premoderation::normalizeMode($board['premoderation'] ?? null) === Premoderation::MODE_OFF) {
                    $this->boardRepo->updatePremoderation($boardId, $accountId, Premoderation::MODE_ANONYMOUS);
                    $this->audit->log('board.premoderation_changed', ['board_id' => $boardId, 'mode' => Premoderation::MODE_ANONYMOUS]);
                }
            }

            $this->boardRepo->updateAnonymousSubmissionEnabled($boardId, $accountId, $enable);
            $this->audit->log($enable ? 'board.anon_submission.enabled' : 'board.anon_submission.disabled', [
                'board_id' => $boardId,
                'actor'    => $this->actorId($request),
            ]);
        } elseif ($action === 'toggle') {
            $enabled = isset($fields['moderation_enabled']) && $fields['moderation_enabled'] === '1';
            $this->modConfigRepo->setModerationEnabled($boardId, $enabled);
            $this->audit->log('board.moderation_toggle', ['board_id' => $boardId, 'enabled' => $enabled]);
        } elseif ($action === 'add') {
            $rawWord = mb_substr(trim((string) ($fields['new_word'] ?? '')), 0, 200, 'UTF-8');

            if ($rawWord === '') {
                $response->getBody()->write((string) json_encode([
                    'error' => [
                        'key'     => 'validation_error',
                        'message' => 'Validation failed.',
                        'fields'  => ['new_word' => 'The word must not be empty.'],
                    ],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            if (mb_strlen($rawWord, 'UTF-8') > 200) {
                $response->getBody()->write((string) json_encode([
                    'error' => [
                        'key'     => 'validation_error',
                        'message' => 'Validation failed.',
                        'fields'  => ['new_word' => 'The word must be at most 200 characters long.'],
                    ],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            $this->modConfigRepo->addWord($boardId, $rawWord);
            $this->audit->log('board.moderation_word_added', ['board_id' => $boardId]);
        } elseif ($action === 'bulk_add') {
            $blob = (string) ($fields['words_blob'] ?? '');

            // Accept newlines, commas and semicolons as separators so plain lists,
            // CSV exports and copy-pasted comma-separated text all work.
            $candidates = preg_split('/[\r\n,;]+/u', $blob);
            $candidates = $candidates === false ? [] : $candidates;
            $words = [];
            foreach ($candidates as $candidate) {
                $word = mb_substr(trim($candidate), 0, 200, 'UTF-8');
                if ($word !== '') {
                    $words[] = $word;
                }
            }

            if ($words === []) {
                $response->getBody()->write((string) json_encode([
                    'error' => [
                        'key'     => 'validation_error',
                        'message' => 'Validation failed.',
                        'fields'  => ['words_blob' => 'The list must contain at least one word.'],
                    ],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            if (count($words) > self::MAX_BULK_WORDS) {
                $response->getBody()->write((string) json_encode([
                    'error' => [
                        'key'     => 'validation_error',
                        'message' => 'Validation failed.',
                        'fields'  => [
                            'words_blob' => sprintf(
                                'At most %d words per submission (found %d). Split the list and add it in batches.',
                                self::MAX_BULK_WORDS,
                                count($words),
                            ),
                        ],
                    ],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            $result = $this->modConfigRepo->addWords($boardId, $words);
            $this->audit->log('board.moderation_words_bulk_added', [
                'board_id' => $boardId,
                'added'    => $result['added'],
                'skipped'  => $result['skipped'],
            ]);

            $response->getBody()->write((string) json_encode(['ok' => true] + $result));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        } elseif ($action === 'remove') {
            $wordId = (int) ($fields['word_id'] ?? 0);
            if ($wordId > 0) {
                $this->modConfigRepo->removeWord($boardId, $wordId);
                $this->audit->log('board.moderation_word_removed', ['board_id' => $boardId]);
            }
        }

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    private function actorId(ServerRequestInterface $request): ?int
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        return is_array($user) && isset($user['id']) ? (int) $user['id'] : null;
    }
}
