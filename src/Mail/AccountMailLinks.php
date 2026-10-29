<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Votepit\Persistence\AccountRepository;

/**
 * Turns the account-relative link path of an event (e.g. "/{board}/idea/12")
 * into the path a participant mail must carry.
 *
 * The SPA adds the account prefix itself (accountPath()), so the in-app
 * notification link_path stays relative. A mail is opened outside the SPA:
 * in cloud path routing (routing_mode: cloud, board URLs
 * "/{account-slug}/{board-slug}/...") it needs the account segment, or the
 * link lands on the wrong page. Self-host has no account segment, so the path
 * is returned unchanged there.
 *
 * Fail-secure: an account that cannot be resolved in cloud mode throws, so
 * the caller does not send a mail with a wrong link.
 */
final readonly class AccountMailLinks
{
    public function __construct(
        private AccountRepository $accounts,
        private string $routingMode,
    ) {}

    /** @throws \RuntimeException when the account is unknown in cloud mode */
    public function path(int $accountId, string $relativePath): string
    {
        if ($this->routingMode !== 'cloud') {
            return $relativePath;
        }

        $account = $this->accounts->findById($accountId);
        $slug    = is_array($account) ? (string) ($account['slug'] ?? '') : '';
        if ($slug === '') {
            throw new \RuntimeException("cannot build a cloud-mode mail link: account {$accountId} not found");
        }

        return '/' . rawurlencode($slug) . $relativePath;
    }
}
