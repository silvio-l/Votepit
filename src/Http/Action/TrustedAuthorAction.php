<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberStandingRepository;

/**
 * Trusted authors of an account (broaden-use-cases issue 11, migration 0089).
 *
 * GET  /admin/trusted-authors                  — authors whose ideas skip
 *                                                pre-moderation in this account.
 * POST /admin/trusted-authors/{userId}/revoke  — clear one author's trust.
 *
 * AuthZ: accountModerate on both (owner|admin|moderator — a moderation
 * decision, like the standing override; anon → 401, other roles → 403).
 * CSRF global. Account-scoped by the standing table's primary key: the list
 * only ever reads this account's rows, and revoking someone not trusted
 * here (unknown user, or trusted only in another account) is a 404 without
 * touching anything. Trust is granted from the approval queue
 * (IdeaModerationQueueAction::approveAndTrust()), never here.
 *
 * The list carries only pseudonymous display fields (username, public_id),
 * no email or email HMAC. Audit on revoke: `member.trust_revoked`
 * (reason `manual`).
 */
final readonly class TrustedAuthorAction
{
    public function __construct(
        private AccountMemberStandingRepository $standingRepo,
        private AuditLogger $audit,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $response->getBody()->write((string) json_encode(['authors' => $this->standingRepo->listTrusted($accountId)]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId    = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $targetUserId = is_numeric($args['userId'] ?? null) ? (int) $args['userId'] : 0;

        if ($targetUserId <= 0 || !$this->standingRepo->revokeTrust($accountId, $targetUserId)) {
            return JsonError::respond($response, 404, 'not_found', 'Trusted author not found.');
        }

        $actor = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('member.trust_revoked', [
            'account_id' => $accountId,
            'user_id'    => $targetUserId,
            'actor_id'   => is_array($actor) ? (int) ($actor['id'] ?? 0) : 0,
            'reason'     => 'manual',
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
