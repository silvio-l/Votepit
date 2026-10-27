<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GET / PUT /account/participant-mail-preferences — per-category cadence of the
 * participant mails. A user only ever changes their own row; an invalid value
 * or an unknown category rejects the whole request (no partial write).
 */
final class ParticipantMailPreferencesActionTest extends IntegrationTestCase
{
    /** @param array<string, mixed> $body */
    private function put(array $body, ?int $userId): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/account/participant-mail-preferences')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withHeader('X-CSRF-Token', $token)
            ->withParsedBody($body);

        return $userId === null ? $request : $request->withCookieParams([
            $csrf->cookieName() => $csrf->sign($token),
            'votepit_sess'      => $this->sessionCookie($userId),
        ]);
    }

    private function cadence(int $userId, string $category): string
    {
        return (new \Votepit\Persistence\ParticipantMailRepository($this->conn))->cadenceFor($userId, $category);
    }

    public function test_a_guest_cannot_read_or_change_preferences(): void
    {
        $response = $this->createApp()->handle($this->put(['new_ideas' => 'weekly'], null));
        $read     = $this->createApp()->handle((new ServerRequestFactory())->createServerRequest('GET', '/account/participant-mail-preferences'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(401, $read->getStatusCode());
    }

    public function test_a_put_without_csrf_is_rejected_and_changes_nothing(): void
    {
        $me = $this->insertUser('prefs-csrf@example.com');

        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/account/participant-mail-preferences')
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($me)])
            ->withParsedBody(['new_ideas' => 'weekly']);
        $response = $this->createApp()->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            ParticipantMailCategory::defaultCadence(ParticipantMailCategory::NEW_IDEAS),
            $this->cadence($me, ParticipantMailCategory::NEW_IDEAS),
        );
    }

    public function test_a_valid_update_changes_only_the_named_category_of_the_caller(): void
    {
        $me    = $this->insertUser('prefs-me@example.com');
        $other = $this->insertUser('prefs-other@example.com');

        $response = $this->createApp()->handle($this->put(['new_ideas' => 'weekly'], $me));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ParticipantMailCategory::CADENCE_WEEKLY, $this->cadence($me, ParticipantMailCategory::NEW_IDEAS));
        // The other user's row is untouched.
        self::assertSame(
            ParticipantMailCategory::defaultCadence(ParticipantMailCategory::NEW_IDEAS),
            $this->cadence($other, ParticipantMailCategory::NEW_IDEAS),
        );
    }

    public function test_an_invalid_cadence_rejects_the_whole_request_without_a_partial_write(): void
    {
        $me = $this->insertUser('prefs-partial@example.com');

        // my_ideas=daily is valid, but new_ideas=instant is not offered for that category.
        $response = $this->createApp()->handle($this->put(['my_ideas' => 'daily', 'new_ideas' => 'instant'], $me));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_cadence', json_decode((string) $response->getBody(), true)['error']['key']);
        self::assertSame(
            ParticipantMailCategory::defaultCadence(ParticipantMailCategory::MY_IDEAS),
            $this->cadence($me, ParticipantMailCategory::MY_IDEAS),
            'the valid entry must not be written when another entry is invalid',
        );
    }

    public function test_an_unknown_category_is_rejected_instead_of_silently_ignored(): void
    {
        $me = $this->insertUser('prefs-typo@example.com');

        // "new_idea" (typo) would otherwise return 200 while nothing changes.
        $response = $this->createApp()->handle($this->put(['new_idea' => 'weekly'], $me));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_category', json_decode((string) $response->getBody(), true)['error']['key']);
    }
}
