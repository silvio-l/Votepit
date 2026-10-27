<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Nudge\OperatorNudgeKind;
use Votepit\Persistence\OperatorNudgeRepository;

/**
 * GET /account/operator-nudges — the caller's three nudge switches.
 * PUT /account/operator-nudges — set them: body { onboarding: bool, quiet_board: bool, plan_limit: bool }.
 *
 * AuthZ: user (own row only, no id in the path — there is no way to address
 * another user's switches). Every key is optional; an absent key keeps its
 * value, a non-boolean value is rejected with 422.
 */
final readonly class OperatorNudgePreferencesAction
{
    public function __construct(private OperatorNudgeRepository $nudges) {}

    public function get(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($this->nudges->preferences($this->currentUserId($request))));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function put(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        foreach (OperatorNudgeKind::all() as $kind) {
            if (array_key_exists($kind->value, $body) && !is_bool($body[$kind->value])) {
                $response->getBody()->write((string) json_encode([
                    'error' => ['key' => 'invalid_preference', 'message' => 'Each nudge switch must be true or false.'],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }
        }

        foreach (OperatorNudgeKind::all() as $kind) {
            if (array_key_exists($kind->value, $body)) {
                $this->nudges->setPreference($userId, $kind, $body[$kind->value]);
            }
        }

        $response->getBody()->write((string) json_encode(['ok' => true, ...$this->nudges->preferences($userId)]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function currentUserId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }
}
