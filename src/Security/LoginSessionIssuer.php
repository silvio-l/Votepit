<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;
use Votepit\Config;
use Votepit\Persistence\AccountMemberRepository;

/**
 * Shared "issue a real session" code path — shared between
 * LoginVerifyAction (magic link, no 2FA), LoginPasswordAction (password,
 * no 2FA), and Login2faAction (second step after TOTP/backup code).
 *
 * Extracts the redirect logic that previously lived inline in
 * LoginVerifyAction (cloud mode: bare '/' → account-scoped dashboard, if the
 * user has at least one membership), so all three login paths behave
 * identically instead of diverging.
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
        if ($returnTo === '/' && $this->config->routingMode === 'cloud') {
            $memberships = $this->accountMembers->membershipsWithSlugFor($userId);
            if ($memberships !== []) {
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
                $returnTo = in_array($membership['role'], ['owner', 'admin'], true)
                    ? '/' . $membership['account_slug'] . '/admin/boards'
                    : '/' . $membership['account_slug'];
            }
        }

        $response->getBody()->write((string) json_encode([
            'ok'       => true,
            'redirect' => $returnTo,
        ]));

        return $this->sessions->issue(
            $response->withStatus(200)->withHeader('Content-Type', 'application/json'),
            ['uid' => $userId, 'v' => $tokenVersion],
        );
    }
}
