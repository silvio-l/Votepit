<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;

/**
 * GET  /operator/accounts               — platform-wide account list.
 * POST /operator/accounts/{id}/lock     — lock ANY account (reversible).
 * POST /operator/accounts/{id}/unlock   — unlock ANY account.
 * POST /operator/accounts/{id}/delete   — hard-delete ANY account.
 *
 * AuthZ: AuthZMiddleware::operator() — STRICTLY above account-scoping. Every
 * method below operates by account ID with NO account_id-scoped WHERE clause
 * on the caller's own account — that is the entire point of this route: an
 * operator acts on any tenant's account regardless of who owns it.
 *
 * Every mutation is audit-logged via the existing AuditLogger, tagged
 * `actor_tier => 'operator'` so operator actions are distinguishable from
 * regular account-scoped audit entries at a glance.
 */
final readonly class OperatorAccountAction
{
    public function __construct(
        private AccountRepository $accounts,
        private AuditLogger $audit,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $response->getBody()->write((string) json_encode([
            'accounts' => $this->accounts->listAllForOperator(),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function lock(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $account   = $accountId > 0 ? $this->accounts->findById($accountId) : null;
        if (!is_array($account)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Account not found.']]);
        }

        $this->accounts->lockAccount($accountId);

        $this->audit->log('operator.account.locked', [
            'actor_tier'   => 'operator',
            'actor_id'     => $this->actorId($request),
            'account_id'   => $accountId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function unlock(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $account   = $accountId > 0 ? $this->accounts->findById($accountId) : null;
        if (!is_array($account)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Account not found.']]);
        }

        $this->accounts->unlockAccount($accountId);

        $this->audit->log('operator.account.unlocked', [
            'actor_tier' => 'operator',
            'actor_id'   => $this->actorId($request),
            'account_id' => $accountId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $account   = $accountId > 0 ? $this->accounts->findById($accountId) : null;
        if (!is_array($account)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Account not found.']]);
        }

        // Fix: the self-host default account (is_default=1, the
        // ONLY account a self-host installation has) must never be
        // deletable — even by an operator. Same guard now also enforced in
        // AccountDeleteAction (self-service GDPR deletion) — see its class
        // doc; this was a pre-existing gap here, closed alongside it.
        if ((bool) ($account['is_default'] ?? false)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'default_account_undeletable', 'message' => 'The self-host default account cannot be deleted.'],
            ]);
        }

        $this->accounts->deleteAccount($accountId);

        $this->audit->log('operator.account.deleted', [
            'actor_tier' => 'operator',
            'actor_id'   => $this->actorId($request),
            'account_id' => $accountId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    private function actorId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $actor */
        $actor = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
