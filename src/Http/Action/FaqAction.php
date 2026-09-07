<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\FaqRepository;
use Votepit\Support\SupportCategory;

/**
 * GET  /faq                          — published FAQ entries (public, both languages).
 * GET  /operator/faq                 — every entry incl. drafts (operator).
 * POST /operator/faq                 — create an entry.
 * PUT  /operator/faq/{id}            — update an entry.
 * DELETE /operator/faq/{id}          — delete an entry.
 *
 * publicList() is anon and platform-wide (no account scoping — one shared
 * knowledge base, mirroring legal footer links). Used both standalone (a
 * dashboard "Help"/FAQ view) and to deflect support requests: the contact
 * form (SupportPage) filters this same list client-side by the category the
 * customer picked, before they ever write a message — see
 * migrations/0023_add_support_and_faq.sql class doc.
 *
 * Every mutation is operator-only (AuthZMiddleware::operator()) and audit-
 * logged, tagged actor_tier=operator, mirroring AbuseReportAction/
 * SupportRequestAction's operator routes.
 */
final readonly class FaqAction
{
    public function __construct(
        private FaqRepository $faq,
        private AuditLogger $audit,
    ) {}

    public function publicList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $entries = array_map($this->present(...), $this->faq->listPublished());
        return $this->json($response, 200, ['entries' => $entries]);
    }

    public function operatorList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $entries = array_map($this->present(...), $this->faq->listAllForOperator());
        return $this->json($response, 200, ['entries' => $entries]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $fields = $this->validate($request);
        if (isset($fields['__errors'])) {
            /** @var array<string, string> $errors */
            $errors = $fields['__errors'];
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $errors],
            ]);
        }

        $id = $this->faq->create(
            (string) $fields['category'],
            (string) $fields['question_de'],
            (string) $fields['question_en'],
            (string) $fields['answer_de'],
            (string) $fields['answer_en'],
            (int) $fields['sort_order'],
            (bool) $fields['is_published'],
        );

        $this->audit->log('operator.faq.created', [
            'actor_tier' => 'operator',
            'actor_id'   => $this->actorId($request),
            'faq_id'     => $id,
        ]);

        return $this->json($response, 201, ['ok' => true, 'id' => $id]);
    }

    /** @param array<string, mixed> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        if ($id <= 0 || $this->faq->findById($id) === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Entry not found.']]);
        }

        $fields = $this->validate($request);
        if (isset($fields['__errors'])) {
            /** @var array<string, string> $errors */
            $errors = $fields['__errors'];
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $errors],
            ]);
        }

        $this->faq->update(
            $id,
            (string) $fields['category'],
            (string) $fields['question_de'],
            (string) $fields['question_en'],
            (string) $fields['answer_de'],
            (string) $fields['answer_en'],
            (int) $fields['sort_order'],
            (bool) $fields['is_published'],
        );

        $this->audit->log('operator.faq.updated', [
            'actor_tier' => 'operator',
            'actor_id'   => $this->actorId($request),
            'faq_id'     => $id,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        if ($id <= 0 || $this->faq->findById($id) === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Entry not found.']]);
        }

        $this->faq->delete($id);

        $this->audit->log('operator.faq.deleted', [
            'actor_tier' => 'operator',
            'actor_id'   => $this->actorId($request),
            'faq_id'     => $id,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * Validates create/update body. Returns either the sanitized fields, or
     * ['__errors' => array<string,string>] on failure.
     *
     * @return array<string, mixed>
     */
    private function validate(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        $category    = trim((string) ($body['category'] ?? ''));
        $questionDe  = trim((string) ($body['question_de'] ?? ''));
        $questionEn  = trim((string) ($body['question_en'] ?? ''));
        $answerDe    = trim((string) ($body['answer_de'] ?? ''));
        $answerEn    = trim((string) ($body['answer_en'] ?? ''));
        $sortOrder   = is_numeric($body['sort_order'] ?? null) ? (int) $body['sort_order'] : 0;
        $isPublished = (bool) ($body['is_published'] ?? true);

        /** @var array<string, string> $errors */
        $errors = [];

        if (!SupportCategory::isValid($category)) {
            $errors['category'] = 'Please choose a valid category.';
        }

        $validator = Validation::createValidator();
        $textFields = [
            'question_de' => $questionDe,
            'question_en' => $questionEn,
            'answer_de'   => $answerDe,
            'answer_en'   => $answerEn,
        ];
        foreach ($textFields as $field => $value) {
            foreach ($validator->validate($value, [
                new Assert\NotBlank(message: 'Required field.'),
                new Assert\Length(max: 4000, maxMessage: 'At most {{ limit }} characters.'),
            ]) as $e) {
                $errors[$field] = (string) $e->getMessage();
                break;
            }
        }

        if ($errors !== []) {
            return ['__errors' => $errors];
        }

        return [
            'category'     => $category,
            'question_de'  => $questionDe,
            'question_en'  => $questionEn,
            'answer_de'    => $answerDe,
            'answer_en'    => $answerEn,
            'sort_order'   => $sortOrder,
            'is_published' => $isPublished,
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $entry = [
            'id'          => (int) $row['id'],
            'category'    => (string) $row['category'],
            'question_de' => (string) $row['question_de'],
            'question_en' => (string) $row['question_en'],
            'answer_de'   => (string) $row['answer_de'],
            'answer_en'   => (string) $row['answer_en'],
            'sort_order'  => (int) $row['sort_order'],
        ];

        if (array_key_exists('is_published', $row)) {
            $entry['is_published'] = (bool) $row['is_published'];
            $entry['created_at']   = $row['created_at'];
            $entry['updated_at']   = $row['updated_at'];
        }

        return $entry;
    }

    private function actorId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $actor */
        $actor = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
