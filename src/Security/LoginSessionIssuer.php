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
 * LoginVerifyAction (cloud mode: bare '/' → account-scoped admin dashboard,
 * if the user has exactly one membership), so all three login paths
 * behave identically instead of diverging.
 */
final readonly class LoginSessionIssuer
{
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
                $returnTo = '/' . $memberships[0]['account_slug'] . '/admin/boards';
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
