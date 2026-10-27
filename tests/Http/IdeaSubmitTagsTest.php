<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Votepit\Tests\Support\GuestIdeaTestCase;

/**
 * P-34: optional board tags on POST /{board}/ideas (owner Q10=a).
 *
 * The submitter may pick tags from the board's EXISTING tags. Participants
 * cannot create tags, a tag id is only accepted when it belongs to the same
 * board and the same account, and at most MAX_TAGS_PER_IDEA tags are
 * accepted. Invalid tag input rejects the whole submission before anything
 * is stored (no idea without the requested tags, no partial assignment).
 */
final class IdeaSubmitTagsTest extends GuestIdeaTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function signedInBody(array $overrides = []): array
    {
        return array_merge([
            'title'    => 'Tagged idea',
            'body'     => 'A description that is long enough to pass.',
            '_form_at' => $this->validTimeTrap(),
        ], $overrides);
    }

    private function assignmentCount(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments');
    }

    private function insertedTagId(int $boardId, string $name): int
    {
        return (int) $this->conn->fetchOne('SELECT id FROM idea_tags WHERE board_id = :b AND name = :n', ['b' => $boardId, 'n' => $name]);
    }

    private function tagCount(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tags');
    }

    /** @param array<string, mixed> $body */
    private function signedInSubmit(string $slug, array $body): \Psr\Http\Message\ResponseInterface
    {
        $userId = $this->insertUser('tag-' . bin2hex(random_bytes(4)) . '@example.com');

        return $this->guestApp()->handle($this->request('POST', '/' . $slug . '/ideas', $this->signedInBody($body), userId: $userId));
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_signed_in_submit_assigns_the_selected_board_tags(): void
    {
        $boardId = $this->insertBoard('tags-ok');
        $bug     = $this->insertTag($boardId, 'Bug');
        $ux      = $this->insertTag($boardId, 'UX');

        $response = $this->signedInSubmit('tags-ok', ['tag_ids' => [$bug, $ux]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $ideaId = (int) $this->json($response)['id'];
        $tagIds = array_map(intval(...), $this->conn->fetchFirstColumn('SELECT tag_id FROM idea_tag_assignments WHERE idea_id = :i ORDER BY tag_id', ['i' => $ideaId]));
        self::assertSame([$bug, $ux], $tagIds);
    }

    public function test_submit_without_tag_ids_assigns_nothing(): void
    {
        $this->insertBoard('tags-none');

        $response = $this->signedInSubmit('tags-none', []);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $this->assignmentCount());
    }

    public function test_duplicate_tag_ids_are_collapsed_to_one_assignment(): void
    {
        $boardId = $this->insertBoard('tags-dup');
        $bug     = $this->insertTag($boardId, 'Bug');

        $response = $this->signedInSubmit('tags-dup', ['tag_ids' => [$bug, $bug]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->assignmentCount());
    }

    public function test_exactly_the_maximum_of_five_tags_is_accepted(): void
    {
        $boardId = $this->insertBoard('tags-max');
        $ids     = [];
        for ($i = 1; $i <= 5; ++$i) {
            $ids[] = $this->insertTag($boardId, 'Tag ' . $i);
        }

        $response = $this->signedInSubmit('tags-max', ['tag_ids' => $ids]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(5, $this->assignmentCount());
    }

    public function test_submit_form_payload_lists_only_this_boards_tags_by_name(): void
    {
        $boardId = $this->insertBoard('tags-form');
        $this->insertTag($boardId, 'UX', ['color' => '#22c55e']);
        $this->insertTag($boardId, 'Bug', ['color' => '#ef4444']);
        $other = $this->insertBoard('tags-form-other');
        $this->insertTag($other, 'Foreign');

        $response = $this->guestApp()->handle($this->request('GET', '/tags-form/ideas/new', userId: $this->insertUser('tags-form@example.com')));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame(
            [
                ['id' => $this->insertedTagId($boardId, 'Bug'), 'name' => 'Bug', 'color' => '#ef4444'],
                ['id' => $this->insertedTagId($boardId, 'UX'), 'name' => 'UX', 'color' => '#22c55e'],
            ],
            $data['board']['tags'],
        );
    }

    // -------------------------------------------------------------------------
    // Rejections: nothing stored, no tag created
    // -------------------------------------------------------------------------

    public function test_tag_of_a_foreign_account_is_rejected_and_nothing_is_stored(): void
    {
        $this->insertBoard('tags-tenant-a');
        $foreignAccount = $this->insertAccount(['slug' => 'foreign-' . bin2hex(random_bytes(3))]);
        $foreignBoard   = $this->insertBoard('tags-tenant-b', ['account_id' => $foreignAccount]);
        $foreignTag     = $this->insertTag($foreignBoard, 'Secret');

        $response = $this->signedInSubmit('tags-tenant-a', ['tag_ids' => [$foreignTag]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_tag', $this->errorKey($response));
        self::assertSame(0, $this->ideaCount());
        self::assertSame(0, $this->assignmentCount());
    }

    public function test_tag_of_another_board_in_the_same_account_is_rejected(): void
    {
        $this->insertBoard('tags-cross-a');
        $boardB = $this->insertBoard('tags-cross-b');
        $tagInB = $this->insertTag($boardB, 'Elsewhere');

        $response = $this->signedInSubmit('tags-cross-a', ['tag_ids' => [$tagInB]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
        self::assertSame(0, $this->assignmentCount());
    }

    public function test_unknown_tag_id_is_rejected(): void
    {
        $this->insertBoard('tags-unknown');

        $response = $this->signedInSubmit('tags-unknown', ['tag_ids' => [999999]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
    }

    public function test_one_invalid_id_among_valid_ones_rejects_the_whole_submission(): void
    {
        $boardId = $this->insertBoard('tags-mixed');
        $valid   = $this->insertTag($boardId, 'Bug');

        $response = $this->signedInSubmit('tags-mixed', ['tag_ids' => [$valid, 999999]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
        self::assertSame(0, $this->assignmentCount());
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'text' => ['abc'];
        yield 'nested array' => [[1]];
        yield 'float' => [1.5];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedIds')]
    public function test_malformed_tag_ids_are_rejected(mixed $bad): void
    {
        $this->insertBoard('tags-malformed');

        $response = $this->signedInSubmit('tags-malformed', ['tag_ids' => [$bad]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
    }

    public function test_tag_ids_that_are_not_a_list_are_rejected(): void
    {
        $boardId = $this->insertBoard('tags-scalar');
        $tag     = $this->insertTag($boardId, 'Bug');

        $response = $this->signedInSubmit('tags-scalar', ['tag_ids' => $tag]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
    }

    public function test_more_than_five_tags_are_rejected_with_too_many_tags(): void
    {
        $boardId = $this->insertBoard('tags-too-many');
        $ids     = [];
        for ($i = 1; $i <= 6; ++$i) {
            $ids[] = $this->insertTag($boardId, 'Tag ' . $i);
        }

        $response = $this->signedInSubmit('tags-too-many', ['tag_ids' => $ids]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('too_many_tags', $this->errorKey($response));
        self::assertSame(0, $this->ideaCount());
        self::assertSame(0, $this->assignmentCount());
    }

    public function test_participants_cannot_create_tags_through_the_submit(): void
    {
        $this->insertBoard('tags-no-create');
        $before = $this->tagCount();

        $response = $this->signedInSubmit('tags-no-create', ['tag_names' => ['Brand new'], 'new_tag' => 'Brand new']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($before, $this->tagCount());
        self::assertSame(0, $this->assignmentCount());
    }

    // -------------------------------------------------------------------------
    // Guest path: same tag rules, guest rules unchanged
    // -------------------------------------------------------------------------

    public function test_guest_submission_with_a_board_tag_assigns_it(): void
    {
        $boardId = $this->guestBoard('tags-guest');
        $bug     = $this->insertTag($boardId, 'Bug');

        $response = $this->submitAsGuest($this->guestApp(), '/tags-guest/ideas', overrides: ['tag_ids' => [$bug]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->assignmentCount());
    }

    public function test_guest_submission_with_a_foreign_tag_is_rejected_and_nothing_is_stored(): void
    {
        $this->guestBoard('tags-guest-foreign');
        $foreignAccount = $this->insertAccount(['slug' => 'guest-foreign-' . bin2hex(random_bytes(3))]);
        $foreignBoard   = $this->insertBoard('tags-guest-foreign-b', ['account_id' => $foreignAccount]);
        $foreignTag     = $this->insertTag($foreignBoard, 'Secret');

        $response = $this->submitAsGuest($this->guestApp(), '/tags-guest-foreign/ideas', overrides: ['tag_ids' => [$foreignTag]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
        self::assertSame(0, $this->assignmentCount());
    }

    public function test_guest_submission_stays_the_unchanged_401_with_tags_when_guests_are_off(): void
    {
        $boardId = $this->insertBoard('tags-guest-off');
        $bug     = $this->insertTag($boardId, 'Bug');

        $response = $this->submitAsGuest($this->guestApp(), '/tags-guest-off/ideas', overrides: ['tag_ids' => [$bug]]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
        self::assertSame(0, $this->assignmentCount());
    }
}
