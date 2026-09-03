<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\SlugInvalidReason;
use Votepit\Security\SlugValidator;

/**
 * POST /admin/boards — creates a new board in the current account (AuthZ:
 * accountAdmin, CSRF globally enforced). Adds the write path to the board
 * overview (BoardListAction).
 *
 * accountId comes EXCLUSIVELY from the request attribute resolved by
 * AccountContextMiddleware — never from the client body (account scoping
 * is this repo's most security-critical invariant, CLAUDE.md §🔒).
 *
 * Validation (name, slug via SlugValidator, collision within the own
 * account) runs BEFORE any persistence; invalid → 422 JSON with a fields
 * map, no exception rethrow. BoardRepository::create() additionally stays
 * active as a race backstop against the UNIQUE(account_id, slug) constraint.
 *
 * Plan-limit check BEFORE any validation —
 * $this->planPolicy->boardLimit($plan) against the account's current board
 * count (BoardRepository::countForAccount()). Fail-safe: an unknown/missing
 * plan value makes $this->planPolicy->boardLimit() return 0 (never
 * "unlimited"), so it blocks any further board creation instead of
 * silently allowing it.
 */
final readonly class BoardCreateAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountRepository $accountRepo,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
    ) {}

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $limitError = $this->checkBoardLimit($accountId);
        if ($limitError !== null) {
            return $this->planLimitError($response, $limitError);
        }

        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? $parsed : [];
        $name   = trim((string) ($fields['name'] ?? ''));
        $slug   = trim((string) ($fields['slug'] ?? ''));

        $errors = $this->validate($name, $slug, $accountId);
        if ($errors !== []) {
            return $this->errorResponse($response, $errors);
        }

        $boardId = $this->boardRepo->create($accountId, $slug, $name);
        if ($boardId === null) {
            // Race backstop: collision only detected at INSERT (UNIQUE(account_id, slug)).
            return $this->errorResponse($response, [
                'slug' => 'This slug is already taken in your account.',
            ]);
        }

        $this->audit->log('board.created', ['board_id' => $boardId, 'account_id' => $accountId]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'slug' => $slug, 'name' => $name]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Fail-safe board-count check: if the account is missing (should never
     * happen due to AccountContextMiddleware) or the plan is unknown,
     * $this->planPolicy->boardLimit() returns 0 — deny instead of silent allow.
     */
    private function checkBoardLimit(int $accountId): ?string
    {
        $account = $this->accountRepo->findById($accountId);
        $plan    = is_array($account) ? (string) ($account['plan'] ?? '') : '';

        $limit = $this->planPolicy->boardLimit($plan);
        $count = $this->boardRepo->countForAccount($accountId);

        if ($count >= $limit) {
            return $limit <= 1
                ? 'Your current plan allows only 1 board. Please upgrade to create more boards.'
                : "Your current plan allows at most {$limit} boards. Please upgrade to create more boards.";
        }

        return null;
    }

    private function planLimitError(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'key'     => 'plan_limit_boards',
                'message' => $message,
            ],
        ]));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }

    /** @return array<string, string> */
    private function validate(string $name, string $slug, int $accountId): array
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'The name must not be empty.';
        } elseif (mb_strlen($name, 'UTF-8') > 128) {
            $errors['name'] = 'The name must be at most 128 characters long.';
        }

        $slugReason = SlugValidator::validate($slug);
        if ($slugReason instanceof SlugInvalidReason) {
            $errors['slug'] = $this->slugErrorMessage($slugReason);
        } elseif ($this->boardRepo->findBySlugForAccount($slug, $accountId) !== null) {
            $errors['slug'] = 'This slug is already taken in your account.';
        }

        return $errors;
    }

    /** @param array<string, string> $fields */
    private function errorResponse(ResponseInterface $response, array $fields): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'key'     => 'validation_error',
                'message' => 'Validation failed.',
                'fields'  => $fields,
            ],
        ]));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
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
}
