<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Http\Support\WidgetGate;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardWidgetRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\WidgetJwtVerifier;
use Votepit\Security\WidgetOriginValidator;

/**
 * Owner/admin settings of the embeddable widget (ADR 0005 §2/§7), behind
 * AuthZMiddleware::accountAdmin():
 *
 *  GET  /admin/boards/{slug}/widget         — origins, secret status, JWT
 *       audience, install detection, plan flag. NEVER the secret itself.
 *  PUT  /admin/boards/{slug}/widget         — replace the allowed-origin
 *       list (WidgetOriginValidator; an empty list switches the widget off).
 *  POST /admin/boards/{slug}/widget/secret  — generate/rotate the HS256
 *       secret; returned exactly once in this response, stored encrypted
 *       (EncryptionService context 'board_widget'). Rotation revokes all
 *       outstanding widget tokens (WidgetTokenService fingerprint).
 *
 * Plan gate: the writes require PlanPolicy::widgetAllowed() (422
 * plan_limit_widget); reads stay available after a downgrade so the owner
 * still sees the kept settings.
 */
final readonly class BoardWidgetAction
{
    public function __construct(
        private BoardRepository $boards,
        private BoardWidgetRepository $widgets,
        private WidgetGate $gate,
        private EncryptionService $secretEncryption,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$accountId, $board] = $this->resolve($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        $boardId = (int) $board['id'];
        $config  = $this->widgets->findForBoard($accountId, $boardId);

        $response->getBody()->write((string) json_encode([
            'plan_allowed'      => $this->gate->planAllows($accountId),
            'embeddable'        => ($board['visibility'] ?? '') !== 'private',
            'allowed_origins'   => $config['allowed_origins'] ?? [],
            'max_origins'       => WidgetOriginValidator::MAX_ORIGINS,
            'secret_configured' => ($config['secret_encrypted'] ?? null) !== null,
            'secret_rotated_at' => $config['secret_rotated_at'] ?? null,
            'audience'          => WidgetJwtVerifier::audienceFor($boardId),
            'max_jwt_lifetime'  => WidgetJwtVerifier::MAX_LIFETIME,
            'last_seen_origin'  => $config['last_seen_origin'] ?? null,
            'last_seen_at'      => $config['last_seen_at'] ?? null,
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function put(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$accountId, $board] = $this->resolve($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }
        if (!$this->gate->planAllows($accountId)) {
            return JsonError::respond($response, 422, 'plan_limit_widget', 'The embeddable widget is not available on your current plan.');
        }

        $parsed  = $request->getParsedBody();
        $raw     = is_array($parsed) ? ($parsed['allowed_origins'] ?? null) : null;
        $origins = is_array($raw) ? WidgetOriginValidator::normalize(array_values($raw)) : 'invalid:0';
        if (is_string($origins)) {
            $message = $origins === 'too_many'
                ? 'At most ' . WidgetOriginValidator::MAX_ORIGINS . ' origins are allowed.'
                : 'Each entry must be exactly https://host or https://host:port (no path, no wildcard).';

            return JsonError::respond($response, 422, 'validation_error', 'Validation failed.', [
                'fields' => ['allowed_origins' => $message],
                'reason' => $origins,
            ]);
        }

        $boardId = (int) $board['id'];
        $this->widgets->saveOrigins($accountId, $boardId, $origins);
        $this->audit->log('board.widget_origins_saved', ['board_id' => $boardId, 'origin_count' => count($origins)]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'allowed_origins' => $origins]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function rotateSecret(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$accountId, $board] = $this->resolve($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }
        if (!$this->gate->planAllows($accountId)) {
            return JsonError::respond($response, 422, 'plan_limit_widget', 'The embeddable widget is not available on your current plan.');
        }

        $boardId = (int) $board['id'];
        $secret  = bin2hex(random_bytes(32));
        $this->widgets->saveSecret($accountId, $boardId, $this->secretEncryption->encrypt($secret));
        $this->audit->log('board.widget_secret_rotated', ['board_id' => $boardId]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'secret' => $secret]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    private function resolve(ServerRequestInterface $request, array $args): array
    {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        return [$accountId, $this->boards->findBySlugForAccount($slug, $accountId)];
    }
}
