<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Votepit\Extension\TagSuggestionAvailability;
use Votepit\Tests\Support\GuestIdeaTestCase;
use Votepit\Tests\Support\RecordingTagSuggester;
use Votepit\Tests\Support\StubExtension;

/**
 * P-35: POST /{board}/ideas/tag-suggestions — AI tag suggestions while the
 * submitter writes (owner Q11=a). Core only ever offers the board's EXISTING
 * tags: the suggester sees the title, the body and the tag names of this
 * board, and whatever it answers is intersected with those tags and capped
 * at the submit limit. Guests and foreign boards never reach the suggester.
 */
final class IdeaTagSuggestionsTest extends GuestIdeaTestCase
{
    private const PATH_SUFFIX = '/ideas/tag-suggestions';

    /** @return \Slim\App<null> */
    private function appWith(RecordingTagSuggester $suggester): \Slim\App
    {
        return $this->createApp(
            extensions: [StubExtension::fromOptions(['tag_suggester' => $suggester])],
            config: $this->guestConfig(),
        );
    }

    /** @return array<string, mixed> */
    private function suggestBody(string $title = 'Dark mode for the dashboard', string $body = 'Please add a dark theme.'): array
    {
        return ['title' => $title, 'body' => $body];
    }

    /** @return array<string, mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        return $this->json($response);
    }

    public function test_signed_in_user_gets_the_suggested_board_tags(): void
    {
        $boardId = $this->insertBoard('sugg-ok');
        $ux      = $this->insertTag($boardId, 'UX');
        $bug     = $this->insertTag($boardId, 'Bug');
        $suggester = new RecordingTagSuggester([$ux]);
        $userId    = $this->insertUser('sugg-ok@example.com');

        $response = $this->appWith($suggester)->handle($this->request('POST', '/sugg-ok' . self::PATH_SUFFIX, $this->suggestBody(), userId: $userId));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['suggestions' => [$ux], 'availability' => 'available'], $this->decode($response));
        self::assertCount(1, $suggester->calls);
        self::assertSame(
            [['id' => $bug, 'name' => 'Bug'], ['id' => $ux, 'name' => 'UX']],
            array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name']], $suggester->calls[0]['tags']),
        );
    }

    public function test_the_suggester_receives_only_this_boards_tag_names(): void
    {
        $boardId = $this->insertBoard('sugg-scope');
        $this->insertTag($boardId, 'UX');
        $other = $this->insertBoard('sugg-scope-other');
        $this->insertTag($other, 'Foreign tag name');
        $suggester = new RecordingTagSuggester([]);

        $this->appWith($suggester)->handle($this->request('POST', '/sugg-scope' . self::PATH_SUFFIX, $this->suggestBody(), userId: $this->insertUser('sugg-scope@example.com')));

        $names = array_column($suggester->calls[0]['tags'], 'name');
        self::assertSame(['UX'], $names);
    }

    public function test_answers_naming_a_tag_of_another_board_are_discarded(): void
    {
        $boardId = $this->insertBoard('sugg-foreign');
        $ux      = $this->insertTag($boardId, 'UX');
        $other   = $this->insertBoard('sugg-foreign-other');
        $foreign = $this->insertTag($other, 'Foreign');
        $suggester = new RecordingTagSuggester([$foreign, $ux]);

        $response = $this->appWith($suggester)->handle($this->request('POST', '/sugg-foreign' . self::PATH_SUFFIX, $this->suggestBody(), userId: $this->insertUser('sugg-foreign@example.com')));

        self::assertSame([$ux], $this->decode($response)['suggestions']);
    }

    public function test_at_most_five_suggestions_are_returned(): void
    {
        $boardId = $this->insertBoard('sugg-max');
        $ids     = [];
        for ($i = 1; $i <= 7; ++$i) {
            $ids[] = $this->insertTag($boardId, 'Tag ' . $i);
        }
        $suggester = new RecordingTagSuggester($ids);

        $response = $this->appWith($suggester)->handle($this->request('POST', '/sugg-max' . self::PATH_SUFFIX, $this->suggestBody(), userId: $this->insertUser('sugg-max@example.com')));

        self::assertCount(5, $this->decode($response)['suggestions']);
    }

    public function test_a_switched_off_suggester_is_not_asked(): void
    {
        $boardId = $this->insertBoard('sugg-off');
        $this->insertTag($boardId, 'UX');
        $suggester = new RecordingTagSuggester([], TagSuggestionAvailability::Disabled);

        $response = $this->appWith($suggester)->handle($this->request('POST', '/sugg-off' . self::PATH_SUFFIX, $this->suggestBody(), userId: $this->insertUser('sugg-off@example.com')));

        self::assertSame(['suggestions' => [], 'availability' => 'disabled'], $this->decode($response));
        self::assertSame([], $suggester->calls);
    }

    public function test_without_an_extension_the_answer_is_empty_and_marked_off(): void
    {
        $this->insertBoard('sugg-community');

        $response = $this->createApp(config: $this->guestConfig())->handle($this->request('POST', '/sugg-community' . self::PATH_SUFFIX, $this->suggestBody(), userId: $this->insertUser('sugg-community@example.com')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['suggestions' => [], 'availability' => 'off'], $this->decode($response));
    }

    public function test_short_titles_skip_the_suggester(): void
    {
        $boardId = $this->insertBoard('sugg-short');
        $this->insertTag($boardId, 'UX');
        $suggester = new RecordingTagSuggester([]);

        $response = $this->appWith($suggester)->handle($this->request('POST', '/sugg-short' . self::PATH_SUFFIX, $this->suggestBody(title: 'ab'), userId: $this->insertUser('sugg-short@example.com')));

        self::assertSame([], $this->decode($response)['suggestions']);
        self::assertSame([], $suggester->calls);
    }

    public function test_guests_never_reach_the_suggester(): void
    {
        $boardId = $this->insertBoard('sugg-guest');
        $this->insertTag($boardId, 'UX');
        $suggester = new RecordingTagSuggester([]);

        $response = $this->appWith($suggester)->handle($this->request('POST', '/sugg-guest' . self::PATH_SUFFIX, $this->suggestBody()));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $suggester->calls);
    }

    public function test_an_unknown_board_answers_404_without_asking(): void
    {
        $suggester = new RecordingTagSuggester([]);

        $response = $this->appWith($suggester)->handle($this->request('POST', '/does-not-exist' . self::PATH_SUFFIX, $this->suggestBody(), userId: $this->insertUser('sugg-404@example.com')));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $suggester->calls);
    }

    public function test_a_board_of_another_account_answers_404_without_asking(): void
    {
        $foreignAccount = $this->insertAccount();
        $this->insertBoard('sugg-foreign-account', ['account_id' => $foreignAccount]);
        $suggester = new RecordingTagSuggester([]);

        $response = $this->appWith($suggester)->handle($this->request('POST', '/sugg-foreign-account' . self::PATH_SUFFIX, $this->suggestBody(), userId: $this->insertUser('sugg-acct@example.com')));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $suggester->calls);
    }

    public function test_the_submit_form_payload_announces_the_suggestion_availability_without_asking(): void
    {
        $this->insertBoard('sugg-form');
        $suggester = new RecordingTagSuggester([], TagSuggestionAvailability::Available);

        $response = $this->appWith($suggester)->handle($this->request('GET', '/sugg-form/ideas/new', userId: $this->insertUser('sugg-form@example.com')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('available', $this->json($response)['board']['tag_suggestion']['availability']);
        self::assertSame([], $suggester->calls, 'Loading the form never asks the suggester.');
    }

    public function test_a_community_install_reports_no_ai_feature_flags(): void
    {
        // Core ships no AI provider: the bootstrap payload must not announce
        // any AI feature, so the SPA shows no AI UI without the Cloud extension.
        $features = $this->json($this->createApp(config: $this->guestConfig())->handle($this->request('GET', '/api/bootstrap')))['features'];

        self::assertArrayNotHasKey('ai_tag_suggestions', $features);
        self::assertArrayNotHasKey('ai_duplicate_confirmation', $features);
    }
}
