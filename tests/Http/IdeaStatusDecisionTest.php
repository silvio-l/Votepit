<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\IdeaStatusDecisionRepository;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Reasoned status decisions (P-01, migration 0091) on
 * POST /{board}/ideas/{id}/status and the public idea detail read.
 *
 * AC1: reason / next_step / responsible_role are optional; a request without
 *      them is accepted exactly as before (backward compatible) and still
 *      writes a history row.
 * AC2: malformed or over-long values → 422 `invalid_decision` naming the field,
 *      idea unchanged, no history row.
 * AC3: the acting user is stored internally, never returned publicly; the
 *      public detail carries the latest decision and a newest-first history.
 * AC4: cross-tenant — a board of another account → 404, nothing written.
 * AC5: the board list carries only a has-reason flag (no text).
 * AC6: the status-change notification carries the plaintext reason.
 */
final class IdeaStatusDecisionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $extra */
    private function postStatus(string $slug, int $ideaId, string $status, ?int $userId, array $extra = []): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/status')
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token, 'status' => $status], $extra));
    }

    private function seedAdmin(): int
    {
        $adminId = $this->insertUser('decision-admin-' . bin2hex(random_bytes(4)) . '@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');

        return $adminId;
    }

    /** @return list<array<string, mixed>> */
    private function decisionRows(int $ideaId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM idea_status_decisions WHERE idea_id = :id ORDER BY id ASC',
            ['id' => $ideaId],
        );

        return $rows;
    }

    private function ideaStatus(int $ideaId): string
    {
        return (string) $this->conn->fetchOne('SELECT status FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    // -------------------------------------------------------------------------
    // AC1 — optional fields, backward compatibility
    // -------------------------------------------------------------------------

    public function test_full_decision_is_recorded_with_actor_internally(): void
    {
        $boardId  = $this->insertBoard('decision-full');
        $adminId  = $this->seedAdmin();
        $authorId = $this->insertUser('decision-full-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Full decision');

        $response = $this->createApp()->handle($this->postStatus('decision-full', $ideaId, 'planned', $adminId, [
            'reason'           => 'Fits the Q3 roadmap.',
            'next_step'        => 'Design review in two weeks.',
            'responsible_role' => 'team',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->decisionRows($ideaId);
        self::assertCount(1, $rows);
        self::assertSame('open', $rows[0]['status_from']);
        self::assertSame('planned', $rows[0]['status_to']);
        self::assertSame('Fits the Q3 roadmap.', $rows[0]['reason']);
        self::assertSame('Design review in two weeks.', $rows[0]['next_step']);
        self::assertSame('team', $rows[0]['responsible_role']);
        self::assertSame($adminId, (int) $rows[0]['actor_user_id']);
    }

    public function test_request_without_decision_fields_is_accepted_and_still_recorded(): void
    {
        $boardId  = $this->insertBoard('decision-legacy');
        $adminId  = $this->seedAdmin();
        $authorId = $this->insertUser('decision-legacy-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Legacy client');

        $response = $this->createApp()->handle($this->postStatus('decision-legacy', $ideaId, 'planned', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('planned', $this->ideaStatus($ideaId));
        $rows = $this->decisionRows($ideaId);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['reason']);
        self::assertNull($rows[0]['next_step']);
        self::assertNull($rows[0]['responsible_role']);
    }

    public function test_blank_decision_fields_are_stored_as_null(): void
    {
        $boardId  = $this->insertBoard('decision-blank');
        $adminId  = $this->seedAdmin();
        $authorId = $this->insertUser('decision-blank-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Blank fields');

        $response = $this->createApp()->handle($this->postStatus('decision-blank', $ideaId, 'planned', $adminId, [
            'reason'           => "   \n ",
            'next_step'        => '',
            'responsible_role' => '',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->decisionRows($ideaId);
        self::assertNull($rows[0]['reason']);
        self::assertNull($rows[0]['next_step']);
        self::assertNull($rows[0]['responsible_role']);
    }

    public function test_multiline_reason_keeps_line_breaks(): void
    {
        $boardId  = $this->insertBoard('decision-lines');
        $adminId  = $this->seedAdmin();
        $authorId = $this->insertUser('decision-lines-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Lines');

        $this->createApp()->handle($this->postStatus('decision-lines', $ideaId, 'planned', $adminId, [
            'reason' => "First line\nSecond line",
        ]));

        self::assertSame("First line\nSecond line", $this->decisionRows($ideaId)[0]['reason']);
    }

    // -------------------------------------------------------------------------
    // AC2 — validation: 422, field named, nothing written
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidDecisionProvider(): iterable
    {
        yield 'reason too long' => [['reason' => str_repeat('a', 1001)], 'reason'];
        yield 'next_step too long' => [['next_step' => str_repeat('b', 281)], 'next_step'];
        yield 'unknown role' => [['responsible_role' => 'admin'], 'responsible_role'];
        yield 'NUL byte in reason' => [['reason' => "bad\x00text"], 'reason'];
        yield 'control char in next_step' => [['next_step' => "bad\x07text"], 'next_step'];
        yield 'array reason' => [['reason' => ['x']], 'reason'];
        yield 'integer role' => [['responsible_role' => 1], 'responsible_role'];
    }

    /** @param array<string, mixed> $extra */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDecisionProvider')]
    public function test_invalid_decision_returns_422_and_nothing_is_written(array $extra, string $field): void
    {
        $boardId  = $this->insertBoard('decision-invalid');
        $adminId  = $this->seedAdmin();
        $authorId = $this->insertUser('decision-invalid-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Invalid', ['status' => 'open']);

        $response = $this->createApp()->handle($this->postStatus('decision-invalid', $ideaId, 'planned', $adminId, $extra));

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('invalid_decision', $body['error']['key']);
        self::assertSame($field, $body['error']['field']);
        self::assertStringNotContainsString('bad', (string) $response->getBody());
        self::assertSame('open', $this->ideaStatus($ideaId));
        self::assertSame([], $this->decisionRows($ideaId));
    }

    public function test_length_limit_counts_characters_not_bytes(): void
    {
        $boardId  = $this->insertBoard('decision-mb');
        $adminId  = $this->seedAdmin();
        $authorId = $this->insertUser('decision-mb-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Multibyte');

        // 1000 umlauts = 2000 bytes, still exactly at the 1000-character limit.
        $response = $this->createApp()->handle($this->postStatus('decision-mb', $ideaId, 'planned', $adminId, [
            'reason' => str_repeat('ä', 1000),  // export-ok: comment-language
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(str_repeat('ä', 1000), $this->decisionRows($ideaId)[0]['reason']);  // export-ok: comment-language
    }

    // -------------------------------------------------------------------------
    // AC3 — public read: latest decision, newest-first history, no actor
    // -------------------------------------------------------------------------

    public function test_detail_exposes_latest_decision_and_history_without_actor(): void
    {
        $boardId  = $this->insertBoard('decision-detail');
        $adminId  = $this->seedAdmin();
        $authorId = $this->insertUser('decision-detail-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'History idea');

        $this->createApp()->handle($this->postStatus('decision-detail', $ideaId, 'planned', $adminId, [
            'reason' => 'First reason.',
        ]));
        $this->createApp()->handle($this->postStatus('decision-detail', $ideaId, 'in_progress', $adminId, [
            'reason'           => 'Second reason.',
            'next_step'        => 'Ship in the next release.',
            'responsible_role' => 'moderation',
        ]));

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/decision-detail/ideas/' . $ideaId),
        );
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        $decision = $body['idea']['decision'];
        self::assertSame('Second reason.', $decision['reason']);
        self::assertSame('Ship in the next release.', $decision['next_step']);
        self::assertSame('moderation', $decision['responsible_role']);
        self::assertSame('planned', $decision['status_from']);
        self::assertSame('in_progress', $decision['status_to']);

        $history = $body['idea']['decision_history'];
        self::assertCount(2, $history);
        self::assertSame('Second reason.', $history[0]['reason']);
        self::assertSame('First reason.', $history[1]['reason']);

        // The acting user never leaves the server.
        self::assertStringNotContainsString('actor', (string) $response->getBody());
        self::assertStringNotContainsString('decision-admin-', (string) $response->getBody());
    }

    public function test_detail_has_null_decision_when_none_recorded(): void
    {
        $boardId  = $this->insertBoard('decision-none');
        $authorId = $this->insertUser('decision-none-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Never decided');

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/decision-none/ideas/' . $ideaId),
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertNull($body['idea']['decision']);
        self::assertSame([], $body['idea']['decision_history']);
    }

    // -------------------------------------------------------------------------
    // AC4 — cross-tenant: foreign account's board → 404, nothing written
    // -------------------------------------------------------------------------

    public function test_foreign_account_board_returns_404_and_writes_nothing(): void
    {
        $otherAccountId = $this->insertAccount();
        $foreignBoardId = $this->insertBoard('decision-foreign', ['account_id' => $otherAccountId]);
        $authorId       = $this->insertUser('decision-foreign-author@example.com');
        $ideaId         = $this->seedIdea($foreignBoardId, $authorId, 'Foreign idea');
        $adminId        = $this->seedAdmin();

        $response = $this->createApp()->handle($this->postStatus('decision-foreign', $ideaId, 'planned', $adminId, [
            'reason' => 'Should never be stored.',
        ]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->decisionRows($ideaId));
        self::assertSame('open', $this->ideaStatus($ideaId));
    }

    public function test_repository_is_board_scoped(): void
    {
        $boardA = $this->insertBoard('decision-scope-a');
        $boardB = $this->insertBoard('decision-scope-b');
        $author = $this->insertUser('decision-scope-author@example.com');
        $ideaId = $this->seedIdea($boardA, $author, 'Scoped');

        $repo = new IdeaStatusDecisionRepository($this->conn);
        $repo->insert($boardA, $ideaId, 'open', 'planned', new \Votepit\Domain\StatusDecision('Reason', null, null), 0);

        self::assertCount(1, $repo->listForIdea($boardA, $ideaId));
        self::assertSame([], $repo->listForIdea($boardB, $ideaId), 'a foreign board id must never see another board\'s history');
    }

    // -------------------------------------------------------------------------
    // AC5 — board list: flag only
    // -------------------------------------------------------------------------

    public function test_board_list_carries_only_a_has_reason_flag(): void
    {
        $boardId  = $this->insertBoard('decision-flag');
        $adminId  = $this->seedAdmin();
        $author   = $this->insertUser('decision-flag-author@example.com');
        $withText = $this->seedIdea($boardId, $author, 'With reason');
        $without  = $this->seedIdea($boardId, $author, 'Without reason');

        $this->createApp()->handle($this->postStatus('decision-flag', $withText, 'planned', $adminId, ['reason' => 'Because.']));
        $this->createApp()->handle($this->postStatus('decision-flag', $without, 'planned', $adminId));

        $rows = (new IdeaRepository($this->conn))->listByBoard($boardId);
        $flags = [];
        foreach ($rows as $row) {
            $flags[(int) $row['id']] = $row['has_decision_reason'];
        }

        self::assertTrue($flags[$withText]);
        self::assertFalse($flags[$without]);
        self::assertArrayNotHasKey('reason', $rows[0]);
    }

    // -------------------------------------------------------------------------
    // AC6 — notification carries the plaintext reason
    // -------------------------------------------------------------------------

    public function test_status_notification_includes_the_reason(): void
    {
        $boardId  = $this->insertBoard('decision-notify');
        $adminId  = $this->seedAdmin();
        $authorId = $this->insertUser('decision-notify-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Notify idea');

        $this->createApp()->handle($this->postStatus('decision-notify', $ideaId, 'planned', $adminId, [
            'reason' => 'Not now, <b>later</b>.',
        ]));

        /** @var list<array<string, mixed>> $notes */
        $notes = $this->conn->fetchAllAssociative(
            "SELECT body FROM notifications WHERE scope = 'user' AND user_id = :u AND type = 'idea_status_changed'",
            ['u' => $authorId],
        );
        self::assertCount(1, $notes);
        self::assertStringContainsString('Reason: Not now, <b>later</b>.', (string) $notes[0]['body']);
    }
}
