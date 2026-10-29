<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\AccountMemberRepository;
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
 * Invite role is chosen by the inviting owner from {member, moderator,
 * admin} — 'owner' is never an accepted value (nobody becomes owner via
 * invite; see also MemberAction::changeRole, which enforces the same rule
 * for re-roling). Defaults to 'member' (least privilege — a private-board
 * voter with no admin/moderation rights) when omitted.
 *
 * Team-size plan-limit check runs BEFORE any other
 * validation — $this->planPolicy->memberLimit($plan) against the current member
 * count (AccountMemberRepository::countForAccount(), counts every role).
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
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private IdentityHasher $hasher,
        private TokenVault $vault,
        private ?Mailer $mailer,
        private SmtpConfigResolver $smtpResolver,
        private Config $config,
        private AuditLogger $audit,
        private Connection $conn,
    ) {}

    public function send(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $actorId   = $this->actorId($request);
        $limit     = $this->teamLimit($accountId, $request);

        // Cheap, non-atomic fast path — see the locked re-check right at the
        // invite INSERT below (issue 18), which is what actually closes the
        // race between this read and the write.
        if ($this->members->countForAccount($accountId) >= $limit) {
            return $this->json($response, 422, [
                'error' => ['key' => 'plan_limit_team', 'message' => $this->teamLimitMessage($limit)],
            ]);
        }

        $parsed   = $request->getParsedBody();
        $rawEmail = is_array($parsed) ? (string) ($parsed['email'] ?? '') : '';
        $email    = strtolower(trim($rawEmail));
        $role     = is_array($parsed) && isset($parsed['role']) ? (string) $parsed['role'] : 'member';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['email' => 'A valid email address is required.'],
                ],
            ]);
        }

        if (!in_array($role, ['member', 'moderator', 'admin'], true)) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['role' => 'role must be "member", "moderator" or "admin".'],
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

        $pair      = $this->vault->generate();
        $expiresAt = (new \DateTimeImmutable('+' . self::INVITE_TTL_SECONDS . ' seconds'))
            ->format('Y-m-d H:i:s');

        // Race-safe re-check + write in ONE transaction, account row locked
        // first (OWASP/BSI audit remediation issue 18 — same pattern as
        // BoardRepository::createWithinLimit()): closes the TOCTOU window
        // between the fast-path check above and the actual insert, so two
        // concurrent invite requests at the team limit can't both succeed.
        $overLimit = $this->conn->transactional(function (Connection $conn) use ($accountId, $invitedId, $actorId, $pair, $expiresAt, $role, $limit): bool {
            if ($conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $conn->fetchOne('SELECT id FROM accounts WHERE id = :account_id FOR UPDATE', ['account_id' => $accountId]);
            } else {
                $conn->fetchOne('SELECT id FROM accounts WHERE id = :account_id', ['account_id' => $accountId]);
            }

            if ($this->members->countForAccount($accountId) >= $limit) {
                return true;
            }

            // Re-invite invalidates a previous open token instead of accumulating.
            $this->invites->deleteOpenForAccountUser($accountId, $invitedId);
            $this->invites->insert($accountId, $invitedId, $actorId, $pair['hash'], $expiresAt, $role);

            return false;
        });

        if ($overLimit) {
            return $this->json($response, 422, [
                'error' => ['key' => 'plan_limit_team', 'message' => $this->teamLimitMessage($limit)],
            ]);
        }

        $link      = $this->config->appUrl . '/invite/accept?token=' . $pair['token'];
        $mailToUse = $this->mailer ?? $this->smtpResolver->buildMailer(null);

        $inviteMail = self::compose($email, $link);

        $mailToUse->send(
            $email,
            $inviteMail['subject'],
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

    /**
     * Subject and rendered body of the invitation mail (shared with
     * bin/send-mail-previews.php).
     *
     * @return array{subject: string, text: string, html: string, image: \Votepit\Mail\InlineImage}
     */
    public static function compose(string $email, string $link): array
    {
        return ['subject' => 'Invitation to Votepit', ...MailTemplate::render(
            'You have been invited',
            [
                'Hello,',
                'you have been invited to join a Votepit account.',
                // The accept link needs a signed-in session: anonymous visitors
                // land on the login page, so say so before they click.
                'To accept, click the button below and sign in with this email address (' . $email . '). '
                . 'No password needed – we will email you a login link, and the invitation is accepted right after you sign in.',
            ],
            $link,
            'Accept invitation',
            ['The link is valid for 7 days.', 'Please do not share it.'],
        )];
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
     * Fail-safe: if the account is missing or the plan is unknown,
     * $this->planPolicy->memberLimit() returns 0 — deny instead of silent
     * allow.
     */
    private function teamLimit(int $accountId, ServerRequestInterface $request): int
    {
        $plan    = $this->plans->forAccount($accountId, $request->getAttribute(AuthNMiddleware::ATTR_USER));

        return $this->planPolicy->memberLimit($plan);
    }

    private function teamLimitMessage(int $limit): string
    {
        return $limit <= 1
            ? 'Your current plan does not allow additional members. Please upgrade to grow your team.'
            : "Your current plan allows at most {$limit} members. Please upgrade to grow your team.";
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
