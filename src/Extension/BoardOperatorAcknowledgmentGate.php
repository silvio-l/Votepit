<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Extension hook for Cloud-only board-operator-responsibility acknowledgment
 * capture at board creation (board-operator-compliance ticket 05) — the
 * per-board counterpart to TermsAcceptanceGate's per-account signup consent
 * (ticket 04): a customer with five boards makes this acknowledgment five
 * times, once per board, because each board is a separate operating
 * decision.
 *
 * Core knows nothing about the `board_operator_acknowledgements` table
 * (Cloud-only schema, see
 * `cloud/migrations/0010_board_operator_acknowledgements.php`) — the
 * contractual relationship this acknowledgment is about only exists for
 * Votepit Cloud customers, exactly like TermsAcceptanceGate.
 * BoardCreateAction consults this gate via
 * ExtensionLoader::boardOperatorAcknowledgmentGate():
 *
 *   - validate() runs BEFORE any write (before name/slug/visibility
 *     validation). A non-null return rejects the whole request with a 422
 *     carrying that string as `error.key` — no board is created.
 *   - record() runs right after BoardRepository::create() succeeds. Unlike
 *     TermsAcceptanceGate::record() (called inside SignupAccountAction's own
 *     transaction), BoardCreateAction never opens a transaction of its own —
 *     a single BoardRepository::create() call is already atomic — so
 *     record() simply follows the insert it documents.
 *
 * Takes the full ExtensionContext (unlike TermsAcceptanceGate, which takes
 * $conn directly) — deliberately: TermsAcceptanceGate is resolved BEFORE
 * ExtensionContext exists yet (SignupAccountAction's route is registered in
 * AppFactory's early "Cloud-Routing safety net" block, see that interface's
 * class doc). POST /admin/boards is registered much later, well after
 * ExtensionContext is built — the same timing this codebase already uses for
 * AccountDeletionPrecondition — so there is no reason to narrow the
 * dependency here.
 *
 * The Community default is NullBoardOperatorAcknowledgmentGate: validate()
 * always accepts (no field is ever required) and record() is a no-op —
 * self-host never asks for or stores this acknowledgment, because there is
 * no Votepit Cloud contract for a self-hosted install to accept.
 */
interface BoardOperatorAcknowledgmentGate
{
    /**
     * @param array<string, mixed> $fields The raw POST body fields submitted to /admin/boards.
     * @return string|null null = accepted; otherwise the `error.key` to reject the request with (422).
     */
    public function validate(array $fields): ?string;

    /** Persists the acknowledgment row for the just-created board. */
    public function record(int $boardId, int $userId): void;

    /**
     * The current acknowledgment status for an existing board — used by
     * BoardOperatorAcknowledgmentAction (board-operator-compliance follow-up,
     * 2026-09-09) to let a board created BEFORE this ticket shipped (or one
     * whose creation-time acknowledgment is otherwise missing) catch up, and
     * to show every board's status in its admin settings. null = not yet
     * acknowledged.
     *
     * @return array{acknowledged_at: string, acknowledged_by_user_id: int}|null
     */
    public function status(int $boardId): ?array;
}
