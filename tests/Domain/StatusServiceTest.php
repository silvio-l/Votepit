<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\StatusService;

/**
 * Unit-Tests für StatusService (Sprint 10, Issue 01).
 *
 * Verifiziert alle erlaubten Übergänge, alle verbotenen Übergänge sowie die
 * Selbst→Selbst-Idempotenz. Kein DB, kein HTTP — reiner Domänenservice.
 */
final class StatusServiceTest extends TestCase
{
    private StatusService $service;

    protected function setUp(): void
    {
        $this->service = new StatusService();
    }

    // -------------------------------------------------------------------------
    // isValidStatus
    // -------------------------------------------------------------------------

    #[DataProvider('validStatusProvider')]
    public function test_valid_statuses_are_recognised(string $status): void
    {
        self::assertTrue($this->service->isValidStatus($status));
    }

    /** @return list<array{string}> */
    public static function validStatusProvider(): array
    {
        return [
            ['open'],
            ['planned'],
            ['in_progress'],
            ['done'],
            ['declined'],
        ];
    }

    public function test_invalid_status_is_rejected(): void
    {
        self::assertFalse($this->service->isValidStatus('unknown'));
        self::assertFalse($this->service->isValidStatus(''));
        self::assertFalse($this->service->isValidStatus('OPEN'));
    }

    // -------------------------------------------------------------------------
    // Selbst→Selbst (idempotenter No-op)
    // -------------------------------------------------------------------------

    #[DataProvider('validStatusProvider')]
    public function test_self_transition_is_allowed_for_all_statuses(string $status): void
    {
        self::assertTrue(
            $this->service->canTransition($status, $status),
            "Self-transition {$status}→{$status} must be allowed (idempotent no-op).",
        );
    }

    // -------------------------------------------------------------------------
    // Erlaubte Übergänge
    // -------------------------------------------------------------------------

    // open → all four targets allowed
    public function test_open_to_planned_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('open', 'planned'));
    }

    public function test_open_to_in_progress_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('open', 'in_progress'));
    }

    public function test_open_to_done_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('open', 'done'));
    }

    public function test_open_to_declined_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('open', 'declined'));
    }

    // planned → all four targets allowed (including back to open)
    public function test_planned_to_in_progress_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('planned', 'in_progress'));
    }

    public function test_planned_to_done_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('planned', 'done'));
    }

    public function test_planned_to_declined_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('planned', 'declined'));
    }

    public function test_planned_to_open_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('planned', 'open'));
    }

    // in_progress → done | declined | planned (back)
    public function test_in_progress_to_done_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('in_progress', 'done'));
    }

    public function test_in_progress_to_declined_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('in_progress', 'declined'));
    }

    public function test_in_progress_to_planned_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('in_progress', 'planned'));
    }

    // done → in_progress (Reopen) | declined
    public function test_done_to_in_progress_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('done', 'in_progress'));
    }

    public function test_done_to_declined_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('done', 'declined'));
    }

    // declined → open
    public function test_declined_to_open_is_allowed(): void
    {
        self::assertTrue($this->service->canTransition('declined', 'open'));
    }

    // -------------------------------------------------------------------------
    // Verbotene Übergänge (PRD §3)
    // -------------------------------------------------------------------------

    public function test_in_progress_to_open_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('in_progress', 'open'));
    }

    public function test_done_to_open_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('done', 'open'));
    }

    public function test_done_to_planned_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('done', 'planned'));
    }

    public function test_declined_to_planned_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('declined', 'planned'));
    }

    public function test_declined_to_in_progress_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('declined', 'in_progress'));
    }

    public function test_declined_to_done_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('declined', 'done'));
    }

    // -------------------------------------------------------------------------
    // Ungültige Status-Werte in der Transition
    // -------------------------------------------------------------------------

    public function test_transition_with_unknown_from_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('unknown', 'open'));
    }

    public function test_transition_with_unknown_to_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('open', 'unknown'));
    }

    public function test_transition_with_both_unknown_is_forbidden(): void
    {
        self::assertFalse($this->service->canTransition('foo', 'bar'));
    }
}
