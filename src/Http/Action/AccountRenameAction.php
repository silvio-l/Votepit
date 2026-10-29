<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Extension\BoardComplianceEnforcer;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;
use Votepit\Security\SlugInvalidReason;
use Votepit\Security\SlugValidator;

/**
 * PUT /admin/account — renames the current account's name and/or slug, and
 * updates its legal-operator identity (AuthZ: accountOwner, same tier as
 * AccountSettingsAction/AccountDeleteAction, CSRF globally enforced).
 *
 * Name/slug: mirrors BoardRenameAction exactly, but the slug is global
 * (SaaS-product-wide unique, see AccountRepository::renameAccount())
 * instead of scoped to an account — same independent-optional-fields
 * pattern (omitted field = no-op, explicit-empty = validation error), same
 * SlugValidator format check, same 422 error shape, same audit logging.
 *
 * legal_operator_name/legal_operator_address/legal_operator_email/
 * legal_operator_notice (board-operator-compliance follow-up,
 * migrations/0058_add_legal_operator_identity.sql,
 * migrations/0064_add_legal_operator_contact.sql): same
 * independent-optional-fields pattern, but here an explicit empty string is
 * NOT a validation error — it clears the field back to NULL (unlike
 * name/slug, which must never be empty). Setting name+address+email is what
 * unblocks BoardCreateAction/BoardBrandingAction from accepting a
 * public-facing board visibility (AccountRepository::hasCompleteLegalOperatorIdentity());
 * legal_operator_notice is always optional (jurisdiction-specific extras).
 *
 * GET /admin/account/slug-available?slug=... — live-check endpoint for the
 * account settings page's debounced availability feedback while typing.
 */
final readonly class AccountRenameAction
{
    public function __construct(
        private AccountRepository $accounts,
        private AuditLogger $audit,
        private BoardComplianceEnforcer $complianceEnforcer,
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

        $currentSlug            = (string) $account['slug'];
        $currentName            = (string) $account['name'];
        $currentOperatorName    = is_string($account['legal_operator_name'] ?? null) ? $account['legal_operator_name'] : null;
        $currentOperatorAddress = is_string($account['legal_operator_address'] ?? null) ? $account['legal_operator_address'] : null;
        $currentOperatorEmail   = is_string($account['legal_operator_email'] ?? null) ? $account['legal_operator_email'] : null;
        $currentOperatorNotice  = is_string($account['legal_operator_notice'] ?? null) ? $account['legal_operator_notice'] : null;

        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? $parsed : [];

        $hasName            = array_key_exists('name', $fields);
        $hasSlug            = array_key_exists('slug', $fields);
        $hasOperatorName    = array_key_exists('legal_operator_name', $fields);
        $hasOperatorAddress = array_key_exists('legal_operator_address', $fields);
        $hasOperatorEmail   = array_key_exists('legal_operator_email', $fields);
        $hasOperatorNotice  = array_key_exists('legal_operator_notice', $fields);

        $newName = $hasName ? trim((string) $fields['name']) : $currentName;
        $newSlug = $hasSlug ? trim((string) $fields['slug']) : $currentSlug;

        // Empty clears back to NULL — unlike name/slug, an empty
        // legal-operator field is a valid, meaningful value (see class doc).
        $newOperatorName = $hasOperatorName ? trim((string) $fields['legal_operator_name']) : $currentOperatorName;
        $newOperatorName = $newOperatorName === '' ? null : $newOperatorName;
        $newOperatorAddress = $hasOperatorAddress ? trim((string) $fields['legal_operator_address']) : $currentOperatorAddress;
        $newOperatorAddress = $newOperatorAddress === '' ? null : $newOperatorAddress;
        $newOperatorEmail = $hasOperatorEmail ? trim((string) $fields['legal_operator_email']) : $currentOperatorEmail;
        $newOperatorEmail = $newOperatorEmail === '' ? null : $newOperatorEmail;
        $newOperatorNotice = $hasOperatorNotice ? trim((string) $fields['legal_operator_notice']) : $currentOperatorNotice;
        $newOperatorNotice = $newOperatorNotice === '' ? null : $newOperatorNotice;

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

        if ($hasOperatorName && $newOperatorName !== null && mb_strlen($newOperatorName, 'UTF-8') > 255) {
            $errors['legal_operator_name'] = 'The operator name must be at most 255 characters long.';
        }
        if ($hasOperatorAddress && $newOperatorAddress !== null && mb_strlen($newOperatorAddress, 'UTF-8') > 2000) {
            $errors['legal_operator_address'] = 'The operator address must be at most 2000 characters long.';
        }
        if ($hasOperatorEmail && $newOperatorEmail !== null) {
            if (mb_strlen($newOperatorEmail, 'UTF-8') > 255) {
                $errors['legal_operator_email'] = 'The contact email must be at most 255 characters long.';
            } elseif (filter_var($newOperatorEmail, FILTER_VALIDATE_EMAIL) === false) {
                $errors['legal_operator_email'] = 'This does not look like a valid email address.';
            }
        }
        if ($hasOperatorNotice && $newOperatorNotice !== null && mb_strlen($newOperatorNotice, 'UTF-8') > 4000) {
            $errors['legal_operator_notice'] = 'The additional legal notice must be at most 4000 characters long.';
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

        if ($hasOperatorName || $hasOperatorAddress || $hasOperatorEmail || $hasOperatorNotice) {
            $this->accounts->updateLegalOperatorIdentity(
                $accountId,
                $newOperatorName,
                $newOperatorAddress,
                $newOperatorEmail,
                $newOperatorNotice,
            );
        }

        if ($hasOperatorName || $hasOperatorAddress || $hasOperatorEmail) {
            // Re-evaluate every public-facing board — clearing any of the
            // three required fields freezes any board that's now
            // non-compliant; completing them all can lift an existing
            // freeze (board-operator-compliance follow-up).
            $this->complianceEnforcer->enforceForAccount($accountId);
        }

        $this->audit->log('account.renamed', [
            'account_id' => $accountId,
            'old_slug'   => $currentSlug,
            'new_slug'   => $newSlug,
        ]);

        $response->getBody()->write((string) json_encode([
            'ok'                     => true,
            'slug'                   => $newSlug,
            'name'                   => $newName,
            'legal_operator_name'    => $newOperatorName,
            'legal_operator_address' => $newOperatorAddress,
            'legal_operator_email'   => $newOperatorEmail,
            'legal_operator_notice'  => $newOperatorNotice,
        ]));
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
