<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /admin/boards/{slug}/delete (owner-only,
 * permanent board deletion — see BoardDeleteAction class doc). Self-host
 * routing mode throughout (unprefixed path), mirroring
 * BoardBrandingActionTest's established pattern for board-scoped routes.
 */
final class BoardDeleteActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    public function test_owner_can_delete_a_board_with_correct_confirmation(): void
    {
        $boardId = $this->insertBoard('delete-me-board');
        $ownerId = $this->insertUser('owner-board-delete@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->post(
            '/admin/boards/delete-me-board/delete',
            ['confirm_slug' => 'delete-me-board'],
            $ownerId,
        ));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);

        $row = $this->conn->fetchOne('SELECT id FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertFalse($row);
    }

    public function test_wrong_confirmation_text_is_rejected_and_board_survives(): void
    {
        $boardId = $this->insertBoard('keep-me-board');
        $ownerId = $this->insertUser('owner-board-wrong@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->post(
            '/admin/boards/keep-me-board/delete',
            ['confirm_slug' => 'not-the-real-slug'],
            $ownerId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('confirmation_mismatch', $data['error']['key'] ?? null);

        $row = $this->conn->fetchOne('SELECT id FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertSame($boardId, (int) $row);
    }

    public function test_moderator_is_rejected(): void
    {
        $this->insertBoard('mod-cannot-delete');
        $modId = $this->insertUser('mod-board-delete@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->post(
            '/admin/boards/mod-cannot-delete/delete',
            ['confirm_slug' => 'mod-cannot-delete'],
            $modId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_anon_is_rejected(): void
    {
        $this->insertBoard('anon-cannot-delete');

        $response = $this->createApp()->handle($this->post(
            '/admin/boards/anon-cannot-delete/delete',
            ['confirm_slug' => 'anon-cannot-delete'],
            null,
        ));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_foreign_board_is_not_found(): void
    {
        $otherAccountId = $this->insertAccount(['slug' => 'other-account-board-delete']);
        $foreignBoardId = $this->insertBoard('foreign-board', ['account_id' => $otherAccountId]);
        $ownerId        = $this->insertUser('owner-foreign-board@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->post(
            '/admin/boards/foreign-board/delete',
            ['confirm_slug' => 'foreign-board'],
            $ownerId,
        ));

        self::assertSame(404, $response->getStatusCode());

        $row = $this->conn->fetchOne('SELECT id FROM boards WHERE id = :id', ['id' => $foreignBoardId]);
        self::assertSame($foreignBoardId, (int) $row);
    }

    public function test_deleting_a_board_frees_up_the_plan_count_for_new_creation(): void
    {
        // The 'team' synthetic test plan allows 5 boards (see
        // IntegrationTestCase::syntheticPlanPolicy(), same plan
        // BoardCreateActionTest::test_lite_plan_blocks_sixth_board() uses).
        // Fill the account to the limit, delete one, then confirm creation
        // succeeds again — proving countForAccount() (used by both
        // BoardCreateAction's limit check and this billing invariant) counts
        // rows, not "active" boards, so deletion (unlike freezing via
        // active-set) genuinely frees a slot.
        $this->setAccountPlan($this->defaultAccountId(), 'team');
        $ownerId = $this->insertUser('owner-team-plan-board-delete@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        for ($i = 1; $i <= 5; $i++) {
            $this->insertBoard("team-board-{$i}", ['is_default' => 0]);
        }

        $overLimit = $this->createApp()->handle($this->post(
            '/admin/boards',
            ['name' => 'One too many', 'slug' => 'one-too-many'],
            $ownerId,
        ));
        self::assertSame(422, $overLimit->getStatusCode());

        $delete = $this->createApp()->handle($this->post(
            '/admin/boards/team-board-1/delete',
            ['confirm_slug' => 'team-board-1'],
            $ownerId,
        ));
        self::assertSame(200, $delete->getStatusCode());

        $afterDelete = $this->createApp()->handle($this->post(
            '/admin/boards',
            ['name' => 'Now it fits', 'slug' => 'now-it-fits'],
            $ownerId,
        ));
        self::assertSame(201, $afterDelete->getStatusCode());
    }
}
