<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Persistence\AccountMemberRepository;

/**
 * AuthZ: route-level authorization (deny-by-default).
 *
 * Every route MUST carry an AuthZ middleware with the required trust level.
 * Convention (enforced via code review): a route without AuthZ counts as a
 * finding.
 *
 *   AuthZ::anon()         — public (even without login).
 *   AuthZ::user()         — login required.
 *   AuthZ::admin()        — platform admin required (users.is_admin).
 *                            Installation-wide routes with no board
 *                            relation (e.g. /admin/smtp).
 *   AuthZ::accountAdmin() — account role owner|moderator required
 *                            (account_members.role). Board-scoped admin
 *                            routes (e.g. /admin/boards/{slug}/*).
 *                            Separate from users.is_admin — see
 *                            AccountMemberRepository / ADR 0001 §2c.
 *   AuthZ::accountOwner()  — account role EXACTLY owner required.
 *                            Owner-only actions (send/revoke invite,
 *                            remove member/change role) — a moderator
 *                            passes accountAdmin() but not accountOwner().
 *   AuthZ::operator()      — platform operator required (users.is_operator).
 *                            STRICTLY ABOVE accountAdmin()/accountOwner() —
 *                            sits above account scoping, not alongside it:
 *                            an account owner/moderator (even of the
 *                            default account) AND an installation-wide
 *                            platform admin (users.is_admin) do NOT pass
 *                            operator(), only is_operator counts. For the
 *                            platform-wide operator routes (/operator/*) —
 *                            account/board lock/unlock/delete across ALL
 *                            accounts, abuse-report inbox, usage overview.
 *                            Separate from users.is_admin: is_admin is
 *                            self-promotable at login via
 *                            Config::isAdminEmailHmac(), is_operator has NO
 *                            such path — only a direct DB UPDATE sets it
 *                            (see the UserRepository class doc).
 *
 * Only smoke routes use 'anon' before user hydration is wired up; 'user'/
 * 'admin' become effective once user hydration is delivered (before that,
 * ATTR_USER is always null → 'user'/'admin' consistently deny).
 */
final readonly class AuthZMiddleware implements MiddlewareInterface
{
    public const LEVEL_ANON          = 'anon';
    public const LEVEL_USER          = 'user';
    public const LEVEL_ADMIN         = 'admin';
    public const LEVEL_ACCOUNT_ADMIN = 'account_admin';
    public const LEVEL_ACCOUNT_OWNER = 'account_owner';
    public const LEVEL_OPERATOR      = 'operator';

    private function __construct(
        private string $required,
        private ResponseFactoryInterface $responseFactory,
        private ?AccountMemberRepository $accountMembers = null,
    ) {}

    public static function anon(ResponseFactoryInterface $rf): self
    {
        return new self(self::LEVEL_ANON, $rf);
    }

    public static function user(ResponseFactoryInterface $rf): self
    {
        return new self(self::LEVEL_USER, $rf);
    }

    public static function admin(ResponseFactoryInterface $rf): self
    {
        return new self(self::LEVEL_ADMIN, $rf);
    }

    /**
     * Account role required (owner|moderator) — for board-scoped admin
     * routes. Separate from AuthZMiddleware::admin() (users.is_admin,
     * installation-wide).
     */
    public static function accountAdmin(ResponseFactoryInterface $rf, AccountMemberRepository $members): self
    {
        return new self(self::LEVEL_ACCOUNT_ADMIN, $rf, $members);
    }

    /**
     * Account role EXACTLY owner required — send/revoke invite, remove
     * member, change role. Separate from accountAdmin(), which allows
     * owner AND moderator.
     */
    public static function accountOwner(ResponseFactoryInterface $rf, AccountMemberRepository $members): self
    {
        return new self(self::LEVEL_ACCOUNT_OWNER, $rf, $members);
    }

    /**
     * Platform operator required (users.is_operator) — see the class doc
     * above. Needs no AccountMemberRepository: is_operator is already part
     * of the user hydrated by AuthNMiddleware (analogous to admin()/
     * is_admin), entirely without account context.
     */
    public static function operator(ResponseFactoryInterface $rf): self
    {
        return new self(self::LEVEL_OPERATOR, $rf);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        if ($this->required === self::LEVEL_ANON) {
            return $handler->handle($request);
        }

        // From here on: login required.
        if ($user === null) {
            return $this->deny(401);
        }

        if ($this->required === self::LEVEL_ADMIN) {
            // is_admin from the hydrated user. Until user hydration is
            // wired up, admin routes consistently deny (user is null).
            $isAdmin = is_array($user) && (bool) ($user['is_admin'] ?? false);
            if (!$isAdmin) {
                return $this->deny(403);
            }
        }

        if ($this->required === self::LEVEL_ACCOUNT_ADMIN) {
            $accountId = $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
            $userId    = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

            $role = ($this->accountMembers instanceof AccountMemberRepository && is_int($accountId))
                ? $this->accountMembers->roleFor($accountId, $userId)
                : null;

            if (!in_array($role, ['owner', 'moderator'], true)) {
                return $this->deny(403);
            }
        }

        if ($this->required === self::LEVEL_OPERATOR) {
            $isOperator = is_array($user) && (bool) ($user['is_operator'] ?? false);
            if (!$isOperator) {
                return $this->deny(403);
            }
        }

        if ($this->required === self::LEVEL_ACCOUNT_OWNER) {
            $accountId = $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
            $userId    = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

            $role = ($this->accountMembers instanceof AccountMemberRepository && is_int($accountId))
                ? $this->accountMembers->roleFor($accountId, $userId)
                : null;

            if ($role !== 'owner') {
                return $this->deny(403);
            }
        }

        return $handler->handle($request);
    }

    private function deny(int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(self::class . ': access denied.');
        return $response;
    }
}
