<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /reports (public intake) and
 * GET /operator/reports + POST /operator/reports/{id}/review (Operator
 * panel, DSA Art. 16 report intake pipeline).
 */
final class AbuseReportActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function anonPost(string $path, array $body): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withParsedBody(array_merge($body, ['_csrf' => $token]));
    }

    /** @param array<string, mixed> $body */
    private function operatorPost(string $path, int $operatorId, array $body = []): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($operatorId),
                $csrf->cookieName() => $csrf->sign($token),
            ])
            ->withParsedBody(array_merge($body, ['_csrf' => $token]));
    }

    private function operatorGet(string $path, int $operatorId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($operatorId)]);
    }

    private function operator(): int
    {
        return $this->insertUser('operator-report@example.com', ['is_operator' => 1]);
    }

    public function test_anon_submits_a_report_and_it_is_stored(): void
    {
        $app = $this->createApp();

        $response = $app->handle($this->anonPost('/reports', [
            'url'    => '/demo/ideas/1',
            'reason' => 'Contains offensive content and personal attacks.',
        ]));

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);
        self::assertIsInt($data['id']);

        $row = $this->conn->fetchAssociative('SELECT * FROM abuse_reports WHERE id = :id', ['id' => $data['id']]);
        self::assertIsArray($row);
        self::assertSame('open', $row['status']);
        self::assertSame('/demo/ideas/1', $row['target_url']);
    }

    public function test_report_resolves_board_and_idea_when_slugs_match(): void
    {
        $boardId = $this->insertBoard('reportable-board');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author-report@example.com'));
        $app     = $this->createApp();

        $response = $app->handle($this->anonPost('/reports', [
            'url'          => '/reportable-board/ideas/' . $ideaId,
            'reason'       => 'This idea contains spam content and advertising.',
            'account_slug' => 'default',
            'board_slug'   => 'reportable-board',
            'idea_id'      => $ideaId,
        ]));

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);

        $row = $this->conn->fetchAssociative('SELECT * FROM abuse_reports WHERE id = :id', ['id' => $data['id']]);
        self::assertIsArray($row);
        self::assertSame($this->defaultAccountId(), (int) $row['account_id']);
        self::assertSame($boardId, (int) $row['board_id']);
        self::assertSame($ideaId, (int) $row['idea_id']);
    }

    public function test_report_is_stored_even_when_slugs_do_not_resolve(): void
    {
        $app = $this->createApp();

        $response = $app->handle($this->anonPost('/reports', [
            'url'          => '/nonexistent-board/ideas/999',
            'reason'       => 'Reporting a board slug that does not exist (anymore).',
            'account_slug' => 'ghost-account',
            'board_slug'   => 'ghost-board',
        ]));

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);

        $row = $this->conn->fetchAssociative('SELECT * FROM abuse_reports WHERE id = :id', ['id' => $data['id']]);
        self::assertIsArray($row);
        self::assertNull($row['account_id']);
        self::assertNull($row['board_id']);
        self::assertSame('/nonexistent-board/ideas/999', $row['target_url']);
    }

    public function test_missing_reason_is_rejected_with_422(): void
    {
        $app = $this->createApp();

        $response = $app->handle($this->anonPost('/reports', ['url' => '/demo/ideas/1', 'reason' => '']));
        self::assertSame(422, $response->getStatusCode());
    }

    public function test_invalid_reporter_email_is_rejected_with_422(): void
    {
        $app = $this->createApp();

        $response = $app->handle($this->anonPost('/reports', [
            'url'            => '/demo/ideas/1',
            'reason'         => 'A valid reason with enough characters.',
            'reporter_email' => 'not-an-email',
        ]));
        self::assertSame(422, $response->getStatusCode());
    }

    public function test_reporter_email_is_encrypted_at_rest_and_decrypted_for_the_operator(): void
    {
        $app = $this->createApp();

        $submit = $app->handle($this->anonPost('/reports', [
            'url'            => '/demo/ideas/2',
            'reason'         => 'A valid reason with enough characters for the report.',
            'reporter_email' => 'reporter@example.com',
        ]));
        $reportId = json_decode((string) $submit->getBody(), true)['id'];

        $row = $this->conn->fetchAssociative('SELECT reporter_email_enc FROM abuse_reports WHERE id = :id', ['id' => $reportId]);
        self::assertIsArray($row);
        self::assertIsString($row['reporter_email_enc']);
        self::assertStringNotContainsString('reporter@example.com', $row['reporter_email_enc']);

        $operatorId = $this->operator();
        $response   = $app->handle($this->operatorGet('/operator/reports', $operatorId));
        $data       = json_decode((string) $response->getBody(), true);

        $found = null;
        foreach ($data['reports'] as $r) {
            if ($r['id'] === $reportId) {
                $found = $r;
            }
        }
        self::assertNotNull($found);
        self::assertSame('reporter@example.com', $found['reporter_email']);
    }

    public function test_non_operator_cannot_list_or_review_reports(): void
    {
        $reportId = $this->insertAbuseReport();
        $userId   = $this->insertUser('non-op-report@example.com');
        $app      = $this->createApp();

        $listResponse = $app->handle($this->operatorGet('/operator/reports', $userId));
        self::assertSame(403, $listResponse->getStatusCode());

        $reviewResponse = $app->handle($this->operatorPost("/operator/reports/{$reportId}/review", $userId, ['status' => 'dismissed']));
        self::assertSame(403, $reviewResponse->getStatusCode());
    }

    public function test_operator_reviews_a_report_and_it_is_audit_logged(): void
    {
        $reportId   = $this->insertAbuseReport();
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->operatorPost("/operator/reports/{$reportId}/review", $operatorId, ['status' => 'dismissed']));
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT status, reviewed_by FROM abuse_reports WHERE id = :id', ['id' => $reportId]);
        self::assertIsArray($row);
        self::assertSame('dismissed', $row['status']);
        self::assertSame($operatorId, (int) $row['reviewed_by']);

        $log = $this->readAuditLog();
        self::assertStringContainsString('operator.report.reviewed', $log);
        self::assertStringContainsString('"actor_tier":"operator"', $log);
    }

    public function test_invalid_review_status_is_rejected_with_422(): void
    {
        $reportId   = $this->insertAbuseReport();
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->operatorPost("/operator/reports/{$reportId}/review", $operatorId, ['status' => 'bogus']));
        self::assertSame(422, $response->getStatusCode());
    }
}
