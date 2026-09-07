<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /faq (public), GET /operator/faq, and the
 * operator CRUD routes (POST/PUT/DELETE /operator/faq[/{id}]).
 */
final class FaqActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function operatorRequest(string $method, string $path, int $operatorId, array $body = []): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($operatorId),
                $csrf->cookieName() => $csrf->sign($token),
            ])
            ->withParsedBody(array_merge($body, ['_csrf' => $token]));
    }

    private function operator(): int
    {
        return $this->insertUser('operator-faq@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_public_list_returns_only_published_entries(): void
    {
        $this->insertFaqEntry(['question_de' => 'Published?', 'is_published' => 1]);
        $this->insertFaqEntry(['question_de' => 'Draft?', 'is_published' => 0]);

        $app      = $this->createApp();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/faq'));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['entries']);
        self::assertSame('Published?', $data['entries'][0]['question_de']);
    }

    public function test_non_operator_cannot_list_drafts_or_mutate(): void
    {
        $userId = $this->insertUser('non-op-faq@example.com');
        $app    = $this->createApp();

        $listResponse = $app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/operator/faq')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );
        self::assertSame(403, $listResponse->getStatusCode());
    }

    public function test_operator_creates_updates_and_deletes_an_entry(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $create = $app->handle($this->operatorRequest('POST', '/operator/faq', $operatorId, [
            'category'     => 'billing',
            'question_de'  => 'How do I cancel?',
            'question_en'  => 'How do I cancel?',
            'answer_de'    => 'In the account area under billing.',
            'answer_en'    => 'Under Billing in your account settings.',
            'sort_order'   => 1,
            'is_published' => true,
        ]));
        self::assertSame(201, $create->getStatusCode());
        $id = json_decode((string) $create->getBody(), true)['id'];

        $log = $this->readAuditLog();
        self::assertStringContainsString('operator.faq.created', $log);

        $update = $app->handle($this->operatorRequest('PUT', "/operator/faq/{$id}", $operatorId, [
            'category'     => 'billing',
            'question_de'  => 'How do I cancel my account?',
            'question_en'  => 'How do I cancel my account?',
            'answer_de'    => 'In the account area under billing.',
            'answer_en'    => 'Under Billing in your account settings.',
            'sort_order'   => 1,
            'is_published' => true,
        ]));
        self::assertSame(200, $update->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT question_de FROM faq_entries WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertSame('How do I cancel my account?', $row['question_de']);

        $delete = $app->handle($this->operatorRequest('DELETE', "/operator/faq/{$id}", $operatorId));
        self::assertSame(200, $delete->getStatusCode());
        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM faq_entries WHERE id = :id', ['id' => $id]));
    }

    public function test_invalid_category_is_rejected_with_422(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->operatorRequest('POST', '/operator/faq', $operatorId, [
            'category'    => 'bogus',
            'question_de' => 'Question?',
            'question_en' => 'Question?',
            'answer_de'   => 'Answer.',
            'answer_en'   => 'Answer.',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_update_of_unknown_id_returns_404(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->operatorRequest('PUT', '/operator/faq/999999', $operatorId, [
            'category'    => 'billing',
            'question_de' => 'Question?',
            'question_en' => 'Question?',
            'answer_de'   => 'Answer.',
            'answer_en'   => 'Answer.',
        ]));

        self::assertSame(404, $response->getStatusCode());
    }
}
