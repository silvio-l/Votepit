<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Upgrade/downgrade/cancellation lifecycle — verifies that
 * boards.frozen_at rejects every write action (idea submit/vote/comment/
 * edit/withdraw/status/pin) with 423, while the board's public read paths
 * (home, idea detail) keep working exactly as on an unfrozen board — see
 * migrations/0016_add_board_freeze_and_deletion_reminder.sql's class doc for
 * why this is deliberately NOT the same mechanism as the operator's
 * locked_at (which hides the board's public page entirely).
 */
final class BoardFreezeGuardTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function postRequest(string $path, int $userId, array $body = []): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'       => $this->sessionCookie($userId),
            ])
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function freezeBoard(int $boardId): void
    {
        $this->conn->update(
            'boards',
            ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            ['id' => $boardId],
        );
    }

    public function test_idea_create_rejected_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-create');
        $userId  = $this->insertUser('freeze-create@example.com');
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle($this->postRequest('/freeze-create/ideas', $userId, [
            'title' => 'A new title',
            'body'  => 'A sufficiently long description.',
            '_form_at' => (string) (time() - 10),
        ]));

        self::assertSame(423, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('board_frozen', $body['error']['key'] ?? null);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :id', ['id' => $boardId]));
    }

    public function test_vote_rejected_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-vote');
        $userId  = $this->insertUser('freeze-vote@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle(
            $this->postRequest("/freeze-vote/ideas/{$ideaId}/vote", $userId, ['value' => 'up']),
        );

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaId]));
    }

    public function test_comment_create_rejected_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-comment');
        $userId  = $this->insertUser('freeze-comment@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle(
            $this->postRequest("/freeze-comment/ideas/{$ideaId}/comments", $userId, ['body' => 'A comment.']),
        );

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $ideaId]));
    }

    public function test_idea_edit_rejected_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-edit');
        $userId  = $this->insertUser('freeze-edit@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Old title');
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle($this->postRequest("/freeze-edit/ideas/{$ideaId}", $userId, [
            'title' => 'New title',
            'body'  => 'A sufficiently long new description.',
        ]));

        self::assertSame(423, $response->getStatusCode());
        $title = $this->conn->fetchOne('SELECT title FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertSame('Old title', $title);
    }

    public function test_idea_withdraw_rejected_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-withdraw');
        $userId  = $this->insertUser('freeze-withdraw@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle(
            $this->postRequest("/freeze-withdraw/ideas/{$ideaId}/withdraw", $userId),
        );

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_idea_status_rejected_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-status');
        $accountId = $this->defaultAccountId();
        $userId  = $this->insertUser('freeze-status@example.com');
        $this->insertAccountMember($accountId, $userId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle(
            $this->postRequest("/freeze-status/ideas/{$ideaId}/status", $userId, ['status' => 'planned']),
        );

        self::assertSame(423, $response->getStatusCode());
        $status = $this->conn->fetchOne('SELECT status FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertSame('open', $status);
    }

    public function test_idea_pin_rejected_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-pin');
        $accountId = $this->defaultAccountId();
        $userId  = $this->insertUser('freeze-pin@example.com');
        $this->insertAccountMember($accountId, $userId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle(
            $this->postRequest("/freeze-pin/ideas/{$ideaId}/pin", $userId, ['pinned' => true]),
        );

        self::assertSame(423, $response->getStatusCode());
        $pinned = (int) $this->conn->fetchOne('SELECT is_pinned FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertSame(0, $pinned);
    }

    // -------------------------------------------------------------------------
    // Reads keep working on a frozen board.
    // -------------------------------------------------------------------------

    public function test_board_home_still_readable_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-read-home');
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/freeze-read-home'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_idea_detail_still_readable_on_frozen_board(): void
    {
        $boardId = $this->insertBoard('freeze-read-detail');
        $userId  = $this->insertUser('freeze-read-detail@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $this->freezeBoard($boardId);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', "/freeze-read-detail/ideas/{$ideaId}"),
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
