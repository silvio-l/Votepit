<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Domain\AccountRoles;
use Votepit\Domain\PlatformRoles;
use Votepit\Logging\AuthzDenialLogger;
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
 *   AuthZ::accountAdmin() — account role owner|admin required
 *                            (account_members.role). Board/account
 *                            management routes (board CRUD, branding,
 *                            moderation settings, board SMTP, API tokens,
 *                            support, member list). 'moderator' does NOT
 *                            pass this — see accountModerate() below.
 *                            Separate from users.is_admin — see
 *                            AccountMemberRepository / ADR 0001 §2c.
 *   AuthZ::accountModerate() — account role owner|admin|moderator required.
 *                            The one surface 'moderator' is allowed on:
 *                            comment moderation (delete), idea status/pin.
 *                            Strictly narrower than accountAdmin() in
 *                            purpose even though it accepts a superset of
 *                            roles — every accountAdmin() role also passes
 *                            this, but not vice versa.
 *   AuthZ::accountOwner()  — account role EXACTLY owner required.
 *                            Owner-only actions (send/revoke invite,
 *                            remove member/change role) — admin/moderator
 *                            pass accountAdmin()/accountModerate() but not
 *                            accountOwner().
 *   AuthZ::operator()      — platform operator required (users.is_operator).
 *                            STRICTLY ABOVE accountAdmin()/accountOwner() —
 *                            sits above account scoping, not alongside it:
 *                            an account owner/moderator (even of the
 *                            default account) AND an installation-wide
 *                            platform admin (users.is_admin) do NOT pass
 *                            operator(), only is_operator counts. For the
 *                            platform-wide operator routes (/operator/*) —
 *                            account/board lock/unlock/delete across ALL
 *                            accounts, abuse-report inbox, usage overview,
 *                            announcements. More than one user may hold
 *                            is_operator (a trusted backup; the 0040
 *                            singleton was lifted by 0071) — not the same
 *                            thing as a self-hosted install's informal
 *                            "operator" of their own instance, which has no
 *                            such flag. Separate from
 *                            users.is_admin: is_admin is self-promotable at
 *                            login via Config::isAdminEmailHmac(),
 *                            is_operator has NO such path — only a direct
 *                            DB UPDATE sets it (see the UserRepository
 *                            class doc). Mandatory 2FA: denies (403) even a
 *                            real operator whose account has no TOTP set up.
 *   AuthZ::support()       — trusted-helper tier BELOW operator
 *                            (users.is_support OR is_operator — an operator
 *                            always passes support() too). For the
 *                            customer-support-facing subset of the
 *                            operator routes only (/operator/support/*,
 *                            /operator/faq/*) — NOT account/board lock/
 *                            unlock/delete, abuse reports, announcements or
 *                            usage, which stay operator()-only. More than
 *                            one user may hold this.
 *                            Same mandatory-2FA gate as operator().
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
    public const LEVEL_ACCOUNT_ADMIN    = 'account_admin';
    public const LEVEL_ACCOUNT_MODERATE = 'account_moderate';
    public const LEVEL_ACCOUNT_OWNER    = 'account_owner';
    public const LEVEL_OPERATOR      = 'operator';
    public const LEVEL_SUPPORT       = 'support';

    private function __construct(
        private string $required,
        private ResponseFactoryInterface $responseFactory,
        private ?AccountMemberRepository $accountMembers = null,
        private ?AuthzDenialLogger $denialLogger = null,
    ) {}

    public static function anon(ResponseFactoryInterface $rf): self
    {
        return new self(self::LEVEL_ANON, $rf);
    }

    public static function user(ResponseFactoryInterface $rf, ?AuthzDenialLogger $denialLogger = null): self
    {
        return new self(self::LEVEL_USER, $rf, denialLogger: $denialLogger);
    }

    public static function admin(ResponseFactoryInterface $rf, ?AuthzDenialLogger $denialLogger = null): self
    {
        return new self(self::LEVEL_ADMIN, $rf, denialLogger: $denialLogger);
    }

    /**
     * Account role required (owner|admin) — for board/account management
     * routes. 'moderator' does not pass this — see accountModerate().
     * Separate from AuthZMiddleware::admin() (users.is_admin,
     * installation-wide).
     */
    public static function accountAdmin(
        ResponseFactoryInterface $rf,
        AccountMemberRepository $members,
        ?AuthzDenialLogger $denialLogger = null,
    ): self {
        return new self(self::LEVEL_ACCOUNT_ADMIN, $rf, $members, $denialLogger);
    }

    /**
     * Account role required (owner|admin|moderator) — the narrow surface a
     * moderator may act on: comment moderation, idea status/pin. See the
     * class doc's accountModerate() entry for why this accepts a superset
     * of accountAdmin()'s roles despite being the *stricter*-scoped gate.
     */
    public static function accountModerate(
        ResponseFactoryInterface $rf,
        AccountMemberRepository $members,
        ?AuthzDenialLogger $denialLogger = null,
    ): self {
        return new self(self::LEVEL_ACCOUNT_MODERATE, $rf, $members, $denialLogger);
    }

    /**
     * Account role EXACTLY owner required — send/revoke invite, remove
     * member, change role. Separate from accountAdmin(), which allows
     * owner AND moderator.
     */
    public static function accountOwner(
        ResponseFactoryInterface $rf,
        AccountMemberRepository $members,
        ?AuthzDenialLogger $denialLogger = null,
    ): self {
        return new self(self::LEVEL_ACCOUNT_OWNER, $rf, $members, $denialLogger);
    }

    /**
     * Platform operator required (users.is_operator) — see the class doc
     * above. Needs no AccountMemberRepository: is_operator is already part
     * of the user hydrated by AuthNMiddleware (analogous to admin()/
     * is_admin), entirely without account context.
     */
    public static function operator(ResponseFactoryInterface $rf, ?AuthzDenialLogger $denialLogger = null): self
    {
        return new self(self::LEVEL_OPERATOR, $rf, denialLogger: $denialLogger);
    }

    /**
     * Trusted-helper tier below operator (users.is_support OR is_operator)
     * — see the class doc above. Same shape as operator(): no
     * AccountMemberRepository needed, is_support/is_operator/
     * totp_enabled_at are already part of the hydrated user.
     */
    public static function support(ResponseFactoryInterface $rf, ?AuthzDenialLogger $denialLogger = null): self
    {
        return new self(self::LEVEL_SUPPORT, $rf, denialLogger: $denialLogger);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        if ($this->required === self::LEVEL_ANON) {
            return $handler->handle($request);
        }

        // From here on: login required.
        if ($user === null) {
            return $this->deny(401, $request, null);
        }

        if ($this->required === self::LEVEL_ADMIN) {
            // is_admin from the hydrated user. Until user hydration is
            // wired up, admin routes consistently deny (user is null).
            $isAdmin = is_array($user) && (bool) ($user['is_admin'] ?? false);
            if (!$isAdmin) {
                return $this->deny(403, $request, is_array($user) ? $user : null);
            }
        }

        if ($this->required === self::LEVEL_ACCOUNT_ADMIN) {
            $accountId = $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
            $userId    = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

            $role = ($this->accountMembers instanceof AccountMemberRepository && is_int($accountId))
                ? $this->accountMembers->roleFor($accountId, $userId)
                : null;

            if (!AccountRoles::canAdminister($role)) {
                return $this->deny(403, $request, is_array($user) ? $user : null);
            }
        }

        if ($this->required === self::LEVEL_ACCOUNT_MODERATE) {
            $accountId = $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
            $userId    = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

            $role = ($this->accountMembers instanceof AccountMemberRepository && is_int($accountId))
                ? $this->accountMembers->roleFor($accountId, $userId)
                : null;

            if (!AccountRoles::canModerate($role)) {
                return $this->deny(403, $request, is_array($user) ? $user : null);
            }
        }

        if ($this->required === self::LEVEL_OPERATOR || $this->required === self::LEVEL_SUPPORT) {
            $isOperator = PlatformRoles::isOperator($user);
            $isSupport  = is_array($user) && (bool) ($user['is_support'] ?? false);

            $authorized = $this->required === self::LEVEL_OPERATOR ? $isOperator : ($isOperator || $isSupport);
            if (!$authorized) {
                return $this->deny(403, $request, is_array($user) ? $user : null);
            }

            // Mandatory 2FA for both elevated tiers — these accounts are
            // the highest-value targets in the whole system (cross-tenant
            // reach), a compromised password alone must never be enough.
            // $user is already known-array here: reaching this point
            // requires $authorized === true, which requires is_array($user)
            // (see $isOperator/$isSupport above) — PHPStan narrows this.
            $totpEnabled = $user['totp_enabled_at'] !== null;
            if (!$totpEnabled) {
                // Only reached by a genuine operator/support user, so naming
                // the reason leaks nothing to anyone else — it lets the SPA
                // point them at 2FA setup instead of a bare "HTTP 403".
                return $this->deny(403, $request, $user, 'totp_required');
            }
        }

        if ($this->required === self::LEVEL_ACCOUNT_OWNER) {
            $accountId = $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
            $userId    = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

            $role = ($this->accountMembers instanceof AccountMemberRepository && is_int($accountId))
                ? $this->accountMembers->roleFor($accountId, $userId)
                : null;

            if (!AccountRoles::isOwner($role)) {
                return $this->deny(403, $request, is_array($user) ? $user : null);
            }
        }

        return $handler->handle($request);
    }

    /** @param array<string, mixed>|null $user The hydrated user (AuthNMiddleware::ATTR_USER), if any. */
    private function deny(int $status, ServerRequestInterface $request, ?array $user, ?string $errorKey = null): ResponseInterface
    {
        $accountId = $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $path      = $request->getUri()->getPath();
        $uid       = $user !== null ? (int) ($user['id'] ?? 0) : null;

        $this->denialLogger?->logDenied(
            [
                'level'      => $this->required,
                'uid'        => $uid,
                'account_id' => is_int($accountId) ? $accountId : null,
                'path'       => $path,
            ],
            // Sample bucket: same actor (or "anon") hitting the same route
            // repeatedly is exactly the scanner/bot pattern this is meant
            // to dampen, without needing per-request IP extraction here.
            ($uid !== null ? "uid:{$uid}" : 'anon') . ':' . $path,
        );

        $response = $this->responseFactory->createResponse($status);
        if ($errorKey !== null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => $errorKey, 'message' => 'Two-factor authentication is required for this area.'],
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write('Access denied.');
        return $response;
    }
}
