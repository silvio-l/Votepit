<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\ModerationConfigRepository;

/**
 * GET  /admin/boards/{slug}/moderation — moderation settings page (AuthZ: admin).
 * Shows the toggle (on/off) + current board custom words as JSON.
 *
 * POST /admin/boards/{slug}/moderation — saves toggle + word-list changes
 * (AuthZ: admin, CSRF globally enforced). JSON response: 200 ok | 422 error.
 * Three sub-actions via hidden field "action": toggle | add | remove.
 * Invalid input → 422 JSON without 500 (no exception rethrow).
 */
final readonly class BoardModerationAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private ModerationConfigRepository $modConfigRepo,
        private AuditLogger $audit,
    ) {}

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

        $boardId  = (int) $board['id'];
        $rawBody  = $request->getParsedBody();
        $fields   = is_array($rawBody) ? $rawBody : [];
        $action   = (string) ($fields['action'] ?? '');

        if ($action === 'toggle') {
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
}
