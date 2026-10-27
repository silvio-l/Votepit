<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Board switch `status_reason_required` (migration 0115): settings write/read,
 * and the server-side enforcement on every human/agent status path — the web
 * endpoint, the agent REST endpoint and MCP set_idea_status (the latter two
 * delegate to IdeaStatusAction). Flag off = behaviour unchanged.
 */
final class StatusReasonRequiredTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    /** @param array<string, mixed> $overrides */
    private function board(string $slug, array $overrides = []): int
    {
        return $this->insertBoard($slug, $overrides);
    }

    /** Owner of the default account (may moderate and change settings). */
    private function owner(string $email): int
    {
        $userId = $this->insertUser($email);
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'owner');

        return $userId;
    }

    private function idea(int $boardId, int $authorId, string $status = 'open'): int
    {
        return $this->seedIdea($boardId, $authorId, 'Dark mode', ['status' => $status]);
    }

    private function ideaStatus(int $ideaId): string
    {
        return (string) $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]);
    }

    private function decisionCount(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_status_decisions WHERE idea_id = ?', [$ideaId]);
    }

    private function storedFlag(string $slug): int
    {
        return (int) $this->conn->fetchOne('SELECT status_reason_required FROM boards WHERE slug = :s', ['s' => $slug]);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, (string) $response->getBody());

        return $data;
    }

    // --- web: POST /{board}/ideas/{id}/status ---

    private function postStatus(string $slug, int $ideaId, string $status, int $userId, ?string $reason = null): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $fields = ['_csrf' => $token, 'status' => $status];
        if ($reason !== null) {
            $fields['reason'] = $reason;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/status')
            ->withCookieParams([
                $csrf->cookieName()   => $csrf->sign($token),
                'votepit_sess'        => $this->sessionCookie($userId),
            ])
            ->withParsedBody($fields);
    }

    // --- settings: POST /admin/boards/{slug}/branding ---

    /** @param array<string, string> $fields */
    private function brandingPost(string $slug, ?int $userId, array $fields): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();
        $cookies   = ['votepit_csrf' => $csrf->sign($csrfToken)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = (new SessionService(self::APP_KEY, 3600, false))->sign(['uid' => $userId, 'v' => 0]);
        }
        $fields['_csrf'] = $csrfToken;

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/boards/' . $slug . '/branding')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    // --- agent REST + MCP ---

    /** @param array<string, mixed>|null $body */
    private function bearer(string $method, string $path, string $token, ?array $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $body !== null ? $request->withHeader('Content-Type', 'application/json')->withParsedBody($body) : $request;
    }

    private function moderateToken(int $boardId, int $issuerId): string
    {
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $issuerId, $pair['hash'], 'Agent moderate', scope: 'moderate');

        return $pair['token'];
    }

    /** @param array<string, mixed> $arguments */
    private function mcpCall(string $token, array $arguments): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/mcp')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'set_idea_status', 'arguments' => $arguments],
        ]));
        $request->getBody()->rewind();

        return $this->createApp()->handle($request);
    }

    // ---------------------------------------------------------------------
    // Settings: read and write (admin branding rider)
    // ---------------------------------------------------------------------

    public function test_flag_defaults_to_off_and_is_exposed_on_the_settings_get(): void
    {
        $this->board('settings-default');
        $ownerId = $this->owner('settings-default@example.com');

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/admin/boards/settings-default/branding')
                ->withCookieParams(['votepit_sess' => (new SessionService(self::APP_KEY, 3600, false))->sign(['uid' => $ownerId, 'v' => 0])]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['status_reason_required']);
        self::assertSame(0, $this->storedFlag('settings-default'));
    }

    public function test_owner_switches_the_flag_on_and_off(): void
    {
        $this->board('settings-toggle');
        $ownerId = $this->owner('settings-toggle@example.com');
        $app     = $this->createApp();

        $on = $app->handle($this->brandingPost('settings-toggle', $ownerId, ['primary_color' => '#123456', 'status_reason_required' => '1']));
        self::assertSame(200, $on->getStatusCode(), (string) $on->getBody());
        self::assertSame(1, $this->storedFlag('settings-toggle'));

        // A save that does not send the flag keeps it.
        $keep = $app->handle($this->brandingPost('settings-toggle', $ownerId, ['primary_color' => '#123456']));
        self::assertSame(200, $keep->getStatusCode());
        self::assertSame(1, $this->storedFlag('settings-toggle'));

        $off = $app->handle($this->brandingPost('settings-toggle', $ownerId, ['primary_color' => '#123456', 'status_reason_required' => '0']));
        self::assertSame(200, $off->getStatusCode());
        self::assertSame(0, $this->storedFlag('settings-toggle'));
    }

    public function test_a_moderator_cannot_change_the_flag(): void
    {
        $this->board('settings-mod');
        $modId = $this->insertUser('settings-mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->brandingPost('settings-mod', $modId, ['primary_color' => '#123456', 'status_reason_required' => '1']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->storedFlag('settings-mod'));
    }

    public function test_anonymous_request_cannot_change_the_flag(): void
    {
        $this->board('settings-anon');

        $response = $this->createApp()->handle($this->brandingPost('settings-anon', null, ['status_reason_required' => '1']));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->storedFlag('settings-anon'));
    }

    public function test_a_frozen_board_rejects_the_flag_change(): void
    {
        $this->board('settings-frozen', ['frozen_at' => '2026-10-01 00:00:00']);
        $ownerId = $this->owner('settings-frozen@example.com');

        $response = $this->createApp()->handle($this->brandingPost('settings-frozen', $ownerId, ['primary_color' => '#123456', 'status_reason_required' => '1']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('board_frozen', $this->json($response)['error']['key']);
        self::assertSame(0, $this->storedFlag('settings-frozen'));
    }

    public function test_another_account_cannot_switch_the_flag_on_a_foreign_board(): void
    {
        $otherAccount = $this->insertAccount(['slug' => 'srr-other', 'plan' => 'pro']);
        $this->board('srr-foreign', ['account_id' => $otherAccount, 'is_default' => 0]);
        $adminId = $this->owner('srr-cross@example.com');

        $response = $this->createApp()->handle($this->brandingPost('srr-foreign', $adminId, ['status_reason_required' => '1']));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->storedFlag('srr-foreign'));
    }

    // ---------------------------------------------------------------------
    // Enforcement: web endpoint
    // ---------------------------------------------------------------------

    public function test_flag_off_keeps_accepting_a_status_change_without_reason(): void
    {
        $boardId = $this->board('srr-off');
        $ownerId = $this->owner('srr-off@example.com');
        $ideaId  = $this->idea($boardId, $ownerId);

        $response = $this->createApp()->handle($this->postStatus('srr-off', $ideaId, 'planned', $ownerId));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_flag_on_rejects_a_change_without_reason_and_changes_nothing(): void
    {
        $boardId = $this->board('srr-on', ['status_reason_required' => 1]);
        $ownerId = $this->owner('srr-on@example.com');
        $ideaId  = $this->idea($boardId, $ownerId);

        $response = $this->createApp()->handle($this->postStatus('srr-on', $ideaId, 'planned', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('status_reason_required', $this->json($response)['error']['key']);
        self::assertSame('open', $this->ideaStatus($ideaId));
        self::assertSame(0, $this->decisionCount($ideaId));
    }

    public function test_flag_on_rejects_a_blank_reason(): void
    {
        $boardId = $this->board('srr-blank', ['status_reason_required' => 1]);
        $ownerId = $this->owner('srr-blank@example.com');
        $ideaId  = $this->idea($boardId, $ownerId);

        $response = $this->createApp()->handle($this->postStatus('srr-blank', $ideaId, 'planned', $ownerId, "   \t "));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('status_reason_required', $this->json($response)['error']['key']);
        self::assertSame('open', $this->ideaStatus($ideaId));
    }

    public function test_flag_on_accepts_a_change_with_reason_and_stores_it(): void
    {
        $boardId = $this->board('srr-with', ['status_reason_required' => 1]);
        $ownerId = $this->owner('srr-with@example.com');
        $ideaId  = $this->idea($boardId, $ownerId);

        $response = $this->createApp()->handle($this->postStatus('srr-with', $ideaId, 'planned', $ownerId, 'Scheduled for Q4.'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('planned', $this->ideaStatus($ideaId));
        self::assertSame(1, $this->decisionCount($ideaId));
    }

    public function test_flag_on_does_not_require_a_reason_for_a_no_op(): void
    {
        $boardId = $this->board('srr-noop', ['status_reason_required' => 1]);
        $ownerId = $this->owner('srr-noop@example.com');
        $ideaId  = $this->idea($boardId, $ownerId, 'planned');

        $response = $this->createApp()->handle($this->postStatus('srr-noop', $ideaId, 'planned', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->decisionCount($ideaId));
    }

    // ---------------------------------------------------------------------
    // Enforcement: agent REST and MCP (both delegate to IdeaStatusAction)
    // ---------------------------------------------------------------------

    public function test_agent_rest_status_change_needs_a_reason_when_the_flag_is_on(): void
    {
        $boardId = $this->board('srr-rest', ['status_reason_required' => 1]);
        $ownerId = $this->owner('srr-rest@example.com');
        $ideaId  = $this->idea($boardId, $ownerId);
        $token   = $this->moderateToken($boardId, $ownerId);
        $app     = $this->createApp();

        $refused = $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/status', $token, ['status' => 'planned']));
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('status_reason_required', $this->json($refused)['error']['key']);
        self::assertSame('open', $this->ideaStatus($ideaId));

        $accepted = $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/status', $token, ['status' => 'planned', 'reason' => 'Agreed in standup.']));
        self::assertSame(200, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_agent_rest_status_change_without_reason_still_works_when_the_flag_is_off(): void
    {
        $boardId = $this->board('srr-rest-off');
        $ownerId = $this->owner('srr-rest-off@example.com');
        $ideaId  = $this->idea($boardId, $ownerId);
        $token   = $this->moderateToken($boardId, $ownerId);

        $response = $this->createApp()->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/status', $token, ['status' => 'planned']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_mcp_set_idea_status_needs_a_reason_when_the_flag_is_on(): void
    {
        $boardId = $this->board('srr-mcp', ['status_reason_required' => 1]);
        $ownerId = $this->owner('srr-mcp@example.com');
        $ideaId  = $this->idea($boardId, $ownerId);
        $token   = $this->moderateToken($boardId, $ownerId);

        $refused = $this->mcpCall($token, ['id' => $ideaId, 'status' => 'planned']);
        $rpc     = $this->json($refused);
        self::assertTrue($rpc['result']['isError']);
        self::assertSame('status_reason_required', json_decode((string) $rpc['result']['content'][0]['text'], true)['error']['key']);
        self::assertSame('open', $this->ideaStatus($ideaId));

        $accepted = $this->mcpCall($token, ['id' => $ideaId, 'status' => 'planned', 'reason' => 'Agreed in standup.']);
        self::assertFalse($this->json($accepted)['result']['isError']);
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }
}
