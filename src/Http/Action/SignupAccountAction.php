<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\SlugInvalidReason;
use Votepit\Security\SlugValidator;

/**
 * GET/POST /signup/account — Cloud onboarding, step 2 (cloud signup onboarding).
 *
 * Global/identity-scoped route (unprefixed, exactly like /login, /logout,
 * /invite/accept — registered in AppFactory's "Cloud routing safety net"
 * block) — it runs BEFORE any account exists for this user, so there is no
 * {account} path segment to resolve here. Cloud-mode only: AppFactory does
 * not register this route in self-host (Config::routingMode === 'self-host'
 * — self-host already operates exactly one, pre-seeded account and has no
 * use for a second-account-creation flow).
 *
 * AuthZ: user (anon → 401). Reached only via GET /login/verify's existing
 * `redirect` mechanism — the signup SPA page requests the magic link with
 * `r=/signup/account` (ReturnToValidator accepts it, no backend change
 * needed there), so by the time this action ever runs the owner's email is
 * ALREADY proven (LoginVerifyAction calls UserRepository::markVerified()
 * before a session is ever issued). That is exactly what satisfies
 * "confirm-before-public" (ADR 0001 §2c decision 12): create() below stamps
 * accounts.confirmed_at = now() unconditionally, because reaching this
 * action structurally implies the prior magic-link click already happened.
 *
 * One account per signup, no multi-workspace ownership: a user who is
 * ALREADY a member of ANY
 * account (owner or moderator) is rejected with 409 before any
 * validation/mutation — AccountMemberRepository::hasAnyMembership() is the
 * check. GET (status()) exposes the same fact up front so the SPA can skip
 * the picker form entirely for a returning user.
 *
 * First-board creation reuses BoardRepository::create() — the exact same
 * persistence call BoardCreateAction uses — inside the same
 * transaction as the account + owner-membership insert, so a signup either
 * fully succeeds or leaves no partial state behind.
 */
final readonly class SignupAccountAction
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountMemberRepository $accountMembers,
        private BoardRepository $boardRepo,
        private Connection $conn,
        private AuditLogger $audit,
        private PlanPolicy $planPolicy,
    ) {}

    /**
     * GET /signup/account — tells the SPA whether this user already owns/
     * belongs to an account, so it can skip the picker form (one account per
     * signup, decision 17) instead of only discovering that on a failed POST.
     */
    public function status(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $userId = $this->actorId($request);

        return $this->json($response, 200, [
            'has_account' => $this->accountMembers->hasAnyMembership($userId),
        ]);
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $userId = $this->actorId($request);

        // One account per signup (decision 17) — checked BEFORE any
        // validation/mutation, never a silent second workspace.
        if ($this->accountMembers->hasAnyMembership($userId)) {
            return $this->json($response, 409, [
                'error' => [
                    'key'     => 'already_has_account',
                    'message' => 'You already belong to an account — only one account per signup is allowed.',
                ],
            ]);
        }

        $parsed      = $request->getParsedBody();
        $fields      = is_array($parsed) ? $parsed : [];
        $accountName = trim((string) ($fields['account_name'] ?? ''));
        $accountSlug = trim((string) ($fields['account_slug'] ?? ''));
        $boardName   = trim((string) ($fields['board_name'] ?? ''));
        $boardSlug   = trim((string) ($fields['board_slug'] ?? ''));

        $errors = $this->validate($accountName, $accountSlug, $boardName, $boardSlug);
        if ($errors !== []) {
            return $this->errorResponse($response, $errors);
        }

        $confirmedAt = new \DateTimeImmutable();

        /** @var array{account_id: int, board_id: int}|null $result */
        $result = $this->conn->transactional(
            function () use ($accountName, $accountSlug, $boardName, $boardSlug, $userId, $confirmedAt): ?array {
                $accountId = $this->accounts->create($accountSlug, $accountName, $this->planPolicy->initialPlan(), $confirmedAt);
                if ($accountId === null) {
                    // Race backstop: slug collision only detected at INSERT time.
                    return null;
                }

                $this->accountMembers->addMember($accountId, $userId, 'owner');

                $boardId = $this->boardRepo->create($accountId, $boardSlug, $boardName);
                if ($boardId === null) {
                    // Practically ruled out (brand-new account, empty
                    // board namespace) — fail-secure instead of silent data loss:
                    // the exception rolls back the whole transaction.
                    throw new \RuntimeException('SignupAccountAction: unexpected board slug collision in brand-new account');
                }

                return ['account_id' => $accountId, 'board_id' => $boardId];
            },
        );

        if ($result === null) {
            return $this->errorResponse($response, [
                'account_slug' => 'This slug is already taken.',
            ]);
        }

        $this->audit->log('account.signup', [
            'account_id' => $result['account_id'],
            'board_id'   => $result['board_id'],
            'user_id'    => $userId,
        ]);

        return $this->json($response, 201, [
            'ok'           => true,
            'account_slug' => $accountSlug,
            'board_slug'   => $boardSlug,
        ]);
    }

    /** @return array<string, string> */
    private function validate(string $accountName, string $accountSlug, string $boardName, string $boardSlug): array
    {
        $errors = [];

        if ($accountName === '') {
            $errors['account_name'] = 'The name must not be empty.';
        } elseif (mb_strlen($accountName, 'UTF-8') > 128) {
            $errors['account_name'] = 'The name must be at most 128 characters long.';
        }

        $accountSlugReason = SlugValidator::validate($accountSlug);
        if ($accountSlugReason instanceof SlugInvalidReason) {
            $errors['account_slug'] = $this->slugErrorMessage($accountSlugReason);
        } elseif ($this->accounts->findBySlug($accountSlug) !== null) {
            $errors['account_slug'] = 'This slug is already taken.';
        }

        if ($boardName === '') {
            $errors['board_name'] = 'The name must not be empty.';
        } elseif (mb_strlen($boardName, 'UTF-8') > 128) {
            $errors['board_name'] = 'The name must be at most 128 characters long.';
        }

        $boardSlugReason = SlugValidator::validate($boardSlug);
        if ($boardSlugReason instanceof SlugInvalidReason) {
            $errors['board_slug'] = $this->slugErrorMessage($boardSlugReason);
        }
        // No collision check needed: the account is brand-new, so it
        // has no boards yet (unlike BoardCreateAction — existing account).

        return $errors;
    }

    private function slugErrorMessage(SlugInvalidReason $reason): string
    {
        return match ($reason) {
            SlugInvalidReason::InvalidLength => 'The slug must be between 1 and 64 characters long.',
            SlugInvalidReason::InvalidCharacters => 'The slug may only contain lowercase letters, digits and hyphens.',
            SlugInvalidReason::LeadingHyphen => 'The slug must not start with a hyphen.',
            SlugInvalidReason::TrailingHyphen => 'The slug must not end with a hyphen.',
            SlugInvalidReason::DoubleHyphen => 'The slug must not contain consecutive hyphens.',
            SlugInvalidReason::ReservedWord => 'This slug is reserved and cannot be used.',
        };
    }

    private function actorId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $actor */
        $actor = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $fields */
    private function errorResponse(ResponseInterface $response, array $fields): ResponseInterface
    {
        return $this->json($response, 422, [
            'error' => [
                'key'     => 'validation_error',
                'message' => 'Validation failed.',
                'fields'  => $fields,
            ],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
