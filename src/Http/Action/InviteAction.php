<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\TokenVault;

/**
 * POST /admin/invites — invite a member by email (AuthZ:
 * accountOwner; CSRF globally enforced; per-action rate limit invite:send).
 *
 * POST /admin/invites/{id}/revoke — revoke an open invite (AuthZ: accountOwner).
 *
 * Token crypto identical to the magic-link flow (TokenVault: SHA-256 hash in the
 * DB, plaintext only in the mail link — see LoginVerifyAction/AppFactory POST /login).
 * The invited user is — just like an unknown login requester —
 * already created as an unverified users row at this point (UserRepository::
 * findByEmailHmac() ?? create()), so that invites.user_id points to a real row;
 * account_members is only created on accept (InviteAcceptAction).
 *
 * Invite is deliberately ALWAYS role=moderator (acceptance criterion —
 * nobody becomes owner via invite).
 *
 * Team-size plan-limit check runs BEFORE any other
 * validation — $this->planPolicy->memberLimit($plan) against the current member
 * count (AccountMemberRepository::countForAccount(), counts owner+moderator).
 * Free has member_limit=1 (owner-only) → every invite attempt is rejected.
 * Fail-safe: an unknown/missing plan value makes
 * $this->planPolicy->memberLimit() return 0, thus blocking every invite instead
 * of silently allowing it.
 */
final readonly class InviteAction
{
    public const INVITE_TTL_SECONDS = 60 * 60 * 24 * 7; // 7 days

    public function __construct(
        private UserRepository $userRepo,
        private AccountMemberRepository $members,
        private InviteRepository $invites,
        private AccountRepository $accountRepo,
        private PlanPolicy $planPolicy,
        private IdentityHasher $hasher,
        private TokenVault $vault,
        private ?Mailer $mailer,
        private SmtpConfigResolver $smtpResolver,
        private Config $config,
        private AuditLogger $audit,
    ) {}

    public function send(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $actorId   = $this->actorId($request);

        $limitError = $this->checkTeamLimit($accountId);
        if ($limitError !== null) {
            return $this->json($response, 422, [
                'error' => ['key' => 'plan_limit_team', 'message' => $limitError],
            ]);
        }

        $parsed   = $request->getParsedBody();
        $rawEmail = is_array($parsed) ? (string) ($parsed['email'] ?? '') : '';
        $email    = strtolower(trim($rawEmail));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['email' => 'A valid email address is required.'],
                ],
            ]);
        }

        $emailHmac    = $this->hasher->hash($email);
        $invitedUser  = $this->userRepo->findByEmailHmac($emailHmac) ?? $this->userRepo->create($emailHmac);
        $invitedId    = (int) $invitedUser['id'];

        if ($invitedId === $actorId) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['email' => 'You cannot invite yourself.'],
                ],
            ]);
        }

        if ($this->members->roleFor($accountId, $invitedId) !== null) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'already_member',
                    'message' => 'This person is already a member of this account.',
                    'fields'  => ['email' => 'This person is already a member of this account.'],
                ],
            ]);
        }

        // Re-invite invalidates a previous open token instead of accumulating.
        $this->invites->deleteOpenForAccountUser($accountId, $invitedId);

        $pair      = $this->vault->generate();
        $expiresAt = (new \DateTimeImmutable('+' . self::INVITE_TTL_SECONDS . ' seconds'))
            ->format('Y-m-d H:i:s');

        $this->invites->insert($accountId, $invitedId, $actorId, $pair['hash'], $expiresAt, 'moderator');

        $link      = $this->config->appUrl . '/invite/accept?token=' . $pair['token'];
        $mailToUse = $this->mailer ?? new SymfonyMailerAdapter($this->smtpResolver->resolve(null));

        $inviteMail = MailTemplate::render(
            'You have been invited',
            ['Hello,', 'you have been invited to a Votepit account:'],
            $link,
            'Accept invitation',
            ['The link is valid for 7 days.', 'Please do not share it.'],
        );

        $mailToUse->send(
            $email,
            'Invitation to Votepit',
            $inviteMail['text'],
            $inviteMail['html'],
            $inviteMail['image'],
        );

        $this->audit->log('invite.sent', [
            'account_id'     => $accountId,
            'target_user_id' => $invitedId,
            'actor_id'       => $actorId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $inviteId  = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;

        $revoked = $inviteId > 0 && $this->invites->revoke($inviteId, $accountId);

        if (!$revoked) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Invite not found or already completed.'],
            ]);
        }

        $this->audit->log('invite.revoked', [
            'account_id' => $accountId,
            'invite_id'  => $inviteId,
            'actor_id'   => $this->actorId($request),
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * Fail-safe team-size check: if the account is missing or the plan is
     * unknown, $this->planPolicy->memberLimit() returns 0 — deny instead of
     * silent allow.
     */
    private function checkTeamLimit(int $accountId): ?string
    {
        $account = $this->accountRepo->findById($accountId);
        $plan    = is_array($account) ? (string) ($account['plan'] ?? '') : '';

        $limit = $this->planPolicy->memberLimit($plan);
        $count = $this->members->countForAccount($accountId);

        if ($count >= $limit) {
            return $limit <= 1
                ? 'Your current plan does not allow additional members. Please upgrade to grow your team.'
                : "Your current plan allows at most {$limit} members. Please upgrade to grow your team.";
        }

        return null;
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
