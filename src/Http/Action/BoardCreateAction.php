<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Extension\BoardOperatorAcknowledgmentGate;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
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
 * silently allowing it. This early check is a cheap, non-atomic fast path
 * only — the actual race-safe enforcement (issue 18) is
 * BoardRepository::createWithinLimit() re-checking the same limit inside a
 * locked transaction right at the INSERT, so two concurrent requests at the
 * limit can't both succeed.
 *
 * Visibility (fail-secure default, CLAUDE.md §🔒): an explicit `visibility`
 * field is validated against PlanPolicy::ALL_VISIBILITIES and
 * PlanPolicy::isVisibilityAllowed($plan, ...) — same 422/fields shape as
 * BoardBrandingAction. If the field is omitted, the safest visibility the
 * plan allows (PlanPolicy::defaultVisibility()) is used instead of ever
 * falling back to a DB column default — nobody accidentally ends up with a
 * publicly listed board just because the request omitted the field.
 *
 * Legal-operator gate (board-operator-compliance issue 02,
 * migrations/0058_add_legal_operator_identity.sql): a public-facing
 * visibility (anything other than 'private' — the same "reachable by
 * direct link" boundary BoardRepository::findPublicBySlugForAccount() uses)
 * is rejected with the same 422 fields.visibility shape as the other
 * visibility checks above whenever accounts.legal_operator_name is empty —
 * EXPLICIT reject, never a silent downgrade to 'private'. Checked against
 * the final resolved $visibility (explicit or defaultVisibility()), so a
 * plan whose defaultVisibility() itself resolves to a public-facing value
 * is covered too, not just an explicit request.
 *
 * `anonymous_voting_enabled` (optional, setup-wizard board templates):
 * parsed exactly like BoardBrandingAction — only true/'1'/1 turn it on,
 * anything else or omitting it leaves the safe default off. Not plan-gated
 * (every plan may toggle it there too).
 *
 * Board-operator acknowledgment gate (board-operator-compliance issue 05,
 * cloud/migrations/0010_board_operator_acknowledgements.php): an
 * INDEPENDENT, ADDITIONAL check from the legal-operator gate above — not a
 * replacement. $operatorAckGate->validate() runs BEFORE name/slug/visibility
 * validation and rejects with 422 `error.key` (e.g.
 * `operator_acknowledgment_required`) if the request's acknowledgment flag
 * is missing/false; no board is created. On success,
 * $operatorAckGate->record() runs right after the board insert. This class
 * stays completely agnostic of what "acknowledgment" means (field name,
 * storage) — entirely the gate's concern, same seam as
 * SignupAccountAction/TermsAcceptanceGate. Community's default
 * (NullBoardOperatorAcknowledgmentGate) never rejects and never writes
 * anything, so self-host board creation is unaffected.
 */
final readonly class BoardCreateAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountRepository $accountRepo,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
        private BoardOperatorAcknowledgmentGate $operatorAckGate,
    ) {}

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $plan      = $this->plans->forAccount($accountId, $user);
        $limit     = $this->planPolicy->boardLimit($plan);

        // Cheap, non-atomic fast path: reject immediately in the common
        // (non-racing) case without doing any field validation first. NOT
        // the security boundary by itself — BoardRepository::createWithinLimit()
        // below re-checks the same limit inside a locked transaction right
        // at the INSERT, which is what actually closes the race (issue 18).
        if ($this->boardRepo->countForAccount($accountId) >= $limit) {
            return $this->planLimitError($response, $this->limitMessage($limit));
        }

        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? $parsed : [];

        // Board-operator acknowledgment (board-operator-compliance issue
        // 05) — checked before any field validation, mirroring
        // SignupAccountAction's terms-gate check. No-op in self-host
        // (NullBoardOperatorAcknowledgmentGate::validate() always returns
        // null).
        $ackError = $this->operatorAckGate->validate($fields);
        if ($ackError !== null) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => $ackError,
                    'message' => 'You must acknowledge that you are this board\'s operator before creating it.',
                ],
            ]);
        }

        $name = trim((string) ($fields['name'] ?? ''));
        $slug = trim((string) ($fields['slug'] ?? ''));

        $hasVisibility = is_array($parsed) && array_key_exists('visibility', $parsed);
        $rawVisibility = $hasVisibility ? (string) $parsed['visibility'] : '';

        $errors = $this->validate($name, $slug, $accountId);
        if ($hasVisibility && !in_array($rawVisibility, PlanPolicy::ALL_VISIBILITIES, true)) {
            $errors['visibility'] = 'Invalid visibility.';
        } elseif ($hasVisibility && !$this->planPolicy->isVisibilityAllowed($plan, $rawVisibility)) {
            $errors['visibility'] = 'This visibility is not available on your current plan. Please upgrade.';
        }
        if ($errors !== []) {
            return $this->errorResponse($response, $errors);
        }

        $visibility = $hasVisibility ? $rawVisibility : $this->planPolicy->defaultVisibility($plan);

        if ($visibility !== 'private' && !$this->accountRepo->hasCompleteLegalOperatorIdentity($accountId)) {
            return $this->errorResponse($response, [
                'visibility' => 'Set a legal operator name in your account settings before making a board public.',
            ]);
        }

        $anonymousVotingEnabled = in_array($fields['anonymous_voting_enabled'] ?? false, [true, '1', 1], true);

        $result = $this->boardRepo->createWithinLimit($accountId, $slug, $name, $visibility, $limit, $anonymousVotingEnabled);
        if ($result === false) {
            return $this->planLimitError($response, $this->limitMessage($limit));
        }
        if ($result === null) {
            // Race backstop: collision only detected at INSERT (UNIQUE(account_id, slug)).
            return $this->errorResponse($response, [
                'slug' => 'This slug is already taken in your account.',
            ]);
        }
        $boardId = $result;

        $this->audit->log('board.created', ['board_id' => $boardId, 'account_id' => $accountId]);

        // Cloud only (see class doc) — no-op in self-host.
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $this->operatorAckGate->record($boardId, $userId);

        $response->getBody()->write((string) json_encode(['ok' => true, 'slug' => $slug, 'name' => $name, 'visibility' => $visibility]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    private function limitMessage(int $limit): string
    {
        return $limit <= 1
            ? 'Your current plan allows only 1 board. Please upgrade to create more boards.'
            : "Your current plan allows at most {$limit} boards. Please upgrade to create more boards.";
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

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
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
