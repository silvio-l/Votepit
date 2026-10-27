<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;
use Votepit\Config;
use Votepit\Domain\AccountRoles;
use Votepit\Persistence\AccountMemberRepository;

/**
 * Shared "issue a real session" code path — shared between
 * LoginVerifyAction (magic link, no 2FA), LoginPasswordAction (password,
 * no 2FA), Login2faAction (second step after TOTP/backup code), and
 * OAuthCallbackAction (Google/GitHub).
 *
 * Extracts the redirect logic that previously lived inline in
 * LoginVerifyAction (cloud mode: bare '/' → account-scoped dashboard, if the
 * user has at least one membership), so all login paths behave identically
 * instead of diverging.
 *
 * Two entry points, same target resolution: issue() returns the target as
 * JSON for a fetch()-based caller (the SPA reads {redirect} and navigates
 * client-side); issueRedirect() is for a caller reached by a real browser
 * navigation instead (OAuthCallbackAction — the provider redirects the
 * browser here directly, no SPA JS involved) and returns a real 302 to the
 * same target.
 */
final readonly class LoginSessionIssuer
{
    /** Highest-priority role first — picks which membership to land on when a user has several. */
    private const ROLE_PRIORITY = ['owner', 'admin', 'moderator', 'member'];

    public function __construct(
        private SessionService $sessions,
        private AccountMemberRepository $accountMembers,
        private Config $config,
    ) {}

    public function issue(
        ResponseInterface $response,
        int $userId,
        int $tokenVersion,
        string $returnTo,
    ): ResponseInterface {
        $target = $this->resolveTarget($userId, $returnTo);

        $response->getBody()->write((string) json_encode([
            'ok'       => true,
            'redirect' => $target,
        ]));

        return $this->sessions->issue(
            $response->withStatus(200)->withHeader('Content-Type', 'application/json'),
            ['uid' => $userId, 'v' => $tokenVersion],
        );
    }

    /**
     * Same session issuance as issue(), but for a caller reached by a REAL
     * browser navigation rather than a fetch() — OAuthCallbackAction, whose
     * only "client" is the browser following the provider's redirect back
     * here. Nothing ever parses issue()'s {ok, redirect} JSON body in that
     * case (found live on staging 2026-09-07: the raw JSON rendered as the
     * page itself, no redirect ever happened), so this issues a real 302
     * instead, with the same Set-Cookie session.
     */
    public function issueRedirect(
        ResponseInterface $response,
        int $userId,
        int $tokenVersion,
        string $returnTo,
    ): ResponseInterface {
        $target = $this->resolveTarget($userId, $returnTo);

        return $this->sessions->issue(
            $response->withStatus(302)->withHeader('Location', $target),
            ['uid' => $userId, 'v' => $tokenVersion],
        );
    }

    /** Cloud mode: bare '/' → the user's highest-priority account membership's dashboard, if any. */
    private function resolveTarget(int $userId, string $returnTo): string
    {
        if ($returnTo !== '/' || $this->config->routingMode !== 'cloud') {
            return $returnTo;
        }

        $memberships = $this->accountMembers->membershipsWithSlugFor($userId);
        if ($memberships === []) {
            return $returnTo;
        }

        usort(
            $memberships,
            static fn (array $a, array $b): int => array_search($a['role'], self::ROLE_PRIORITY, true)
                <=> array_search($b['role'], self::ROLE_PRIORITY, true),
        );

        $membership = $memberships[0];
        // moderator/member have no access to the admin panel
        // (AuthZMiddleware::accountModerate()/accountAdmin() exclude
        // 'member', and 'moderator' fails accountAdmin() too) — send
        // them to the account's board home instead of a 403.
        return AccountRoles::canAdminister($membership['role'])
            ? '/' . $membership['account_slug'] . '/admin/boards'
            : '/' . $membership['account_slug'];
    }
}
