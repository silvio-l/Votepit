<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;
use Votepit\Security\SlugInvalidReason;
use Votepit\Security\SlugValidator;

/**
 * PUT /admin/account — renames the current account's name and/or slug
 * (AuthZ: accountOwner, same tier as AccountSettingsAction/AccountDeleteAction,
 * CSRF globally enforced).
 *
 * Mirrors BoardRenameAction exactly, but the slug is global (SaaS-product-
 * wide unique, see AccountRepository::renameAccount()) instead of scoped to
 * an account — same independent-optional-fields pattern (omitted field =
 * no-op, explicit-empty = validation error), same SlugValidator format
 * check, same 422 error shape, same audit logging.
 *
 * GET /admin/account/slug-available?slug=... — live-check endpoint for the
 * account settings page's debounced availability feedback while typing.
 */
final readonly class AccountRenameAction
{
    public function __construct(
        private AccountRepository $accounts,
        private AuditLogger $audit,
    ) {}

    public function rename(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $account   = $this->accounts->findById($accountId);
        if (!is_array($account)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Account not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $currentSlug = (string) $account['slug'];
        $currentName = (string) $account['name'];

        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? $parsed : [];

        $hasName = array_key_exists('name', $fields);
        $hasSlug = array_key_exists('slug', $fields);

        $newName = $hasName ? trim((string) $fields['name']) : $currentName;
        $newSlug = $hasSlug ? trim((string) $fields['slug']) : $currentSlug;

        $errors = [];
        if ($hasName) {
            if ($newName === '') {
                $errors['name'] = 'The name must not be empty.';
            } elseif (mb_strlen($newName, 'UTF-8') > 128) {
                $errors['name'] = 'The name must be at most 128 characters long.';
            }
        }

        if ($hasSlug) {
            $slugReason = SlugValidator::validate($newSlug);
            if ($slugReason instanceof SlugInvalidReason) {
                $errors['slug'] = $this->slugErrorMessage($slugReason);
            } elseif ($newSlug !== $currentSlug && !$this->accounts->isSlugAvailable($newSlug, $accountId)) {
                $errors['slug'] = 'This slug is already taken.';
            }
        }

        if ($errors !== []) {
            return $this->errorResponse($response, $errors);
        }

        $ok = $this->accounts->renameAccount($accountId, $newSlug, $newName);
        if (!$ok) {
            // Race/tombstone backstop caught at persistence time — same
            // generic message as above, no tombstone-existence leak.
            return $this->errorResponse($response, [
                'slug' => 'This slug is already taken.',
            ]);
        }

        $this->audit->log('account.renamed', [
            'account_id' => $accountId,
            'old_slug'   => $currentSlug,
            'new_slug'   => $newSlug,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'slug' => $newSlug, 'name' => $newName]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    public function slugAvailable(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $query     = $request->getQueryParams();
        $slug      = is_string($query['slug'] ?? null) ? trim($query['slug']) : '';

        $slugReason = SlugValidator::validate($slug);
        if ($slugReason instanceof SlugInvalidReason) {
            $response->getBody()->write((string) json_encode(['available' => false]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $available = $this->accounts->isSlugAvailable($slug, $accountId);

        $response->getBody()->write((string) json_encode(['available' => $available]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, string> $fields */
    private function errorResponse(ResponseInterface $response, array $fields): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'key'     => 'validation_error',
                'message' => 'Validation failed.',
                'fields'  => $fields,
            ],
        ]));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }

    private function slugErrorMessage(SlugInvalidReason $reason): string
    {
        return match ($reason) {
            SlugInvalidReason::InvalidLength => 'The slug must be between 1 and 64 characters long.',
            SlugInvalidReason::InvalidCharacters => 'The slug may only contain lowercase letters, digits and hyphens.',
            SlugInvalidReason::LeadingHyphen => 'The slug must not start with a hyphen.',
            SlugInvalidReason::TrailingHyphen => 'The slug must not end with a hyphen.',
            SlugInvalidReason::DoubleHyphen => 'The slug must not contain consecutive hyphens.',
            SlugInvalidReason::ReservedWord => 'This slug is reserved and cannot be used.',
        };
    }
}
