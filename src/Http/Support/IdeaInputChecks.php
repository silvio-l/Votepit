<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Domain\ContentModerationService;
use Votepit\Persistence\ModerationConfigRepository;

/**
 * The content checks every idea submission runs — shared by the member path
 * (IdeaCreateAction) and the guest path (AnonymousIdeaSubmission, ADR 0006)
 * so lengths, messages and the word filter cannot drift between them.
 */
final class IdeaInputChecks
{
    private function __construct() {}

    /**
     * Title 3..200, body 10..500 characters (Symfony Validator).
     *
     * @return array<string, string> field => first error message
     */
    public static function fieldErrors(string $title, string $body): array
    {
        $validator   = Validation::createValidator();
        $titleErrors = $validator->validate($title, [
            new Assert\NotBlank(message: 'The title must not be empty.'),
            new Assert\Length(
                min: 3,
                max: 200,
                minMessage: 'The title must be at least {{ limit }} characters long.',
                maxMessage: 'The title must be at most {{ limit }} characters long.',
            ),
        ]);
        $bodyErrors = $validator->validate($body, [
            new Assert\NotBlank(message: 'The description must not be empty.'),
            new Assert\Length(
                min: 10,
                max: 500,
                minMessage: 'The description must be at least {{ limit }} characters long.',
                maxMessage: 'The description must be at most {{ limit }} characters long.',
            ),
        ]);

        $fields = [];
        foreach ($titleErrors as $e) {
            $fields['title'] = (string) $e->getMessage();
            break;
        }
        foreach ($bodyErrors as $e) {
            $fields['body'] = (string) $e->getMessage();
            break;
        }

        return $fields;
    }

    /** @param array<string, string> $fields */
    public static function validationError(ResponseInterface $response, array $fields, string $title, string $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'key'     => 'validation_error',
                'message' => 'Validation failed.',
                'fields'  => $fields,
                'values'  => ['title' => $title, 'body' => $body],
            ],
        ]));

        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Word filter: the base list plus the board's own words. $forced runs
     * it even when the board switched the filter off (guests, ADR 0006 §3).
     * Without a config repository the base list alone applies.
     *
     * @return array{clean: bool, hits: list<string>}
     */
    public static function wordFilter(
        ContentModerationService $moderation,
        ?ModerationConfigRepository $configRepo,
        int $boardId,
        bool $forced,
        string $title,
        string $body,
    ): array {
        $enabled = $forced || !$configRepo instanceof ModerationConfigRepository || $configRepo->isModerationEnabled($boardId);
        if (!$enabled) {
            return ['clean' => true, 'hits' => []];
        }

        $effective = $moderation;
        if ($configRepo instanceof ModerationConfigRepository) {
            $customWords = $configRepo->wordList($boardId);
            if ($customWords !== []) {
                $effective = $moderation->withAdditionalWords($customWords);
            }
        }

        $result = $effective->check($title, $body);

        return ['clean' => $result['clean'], 'hits' => $result['hits']];
    }

    public static function moderationBlocked(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'key'     => 'moderation_blocked',
                'message' => 'Your text contains disallowed terms. Please rephrase it.',
                'fields'  => [],
            ],
        ]));

        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }
}
