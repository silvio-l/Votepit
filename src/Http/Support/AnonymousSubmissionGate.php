<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;

/**
 * Single answer to "may guests submit ideas on this board right now?"
 * (ADR 0006 §8/§9), shared by the submit path, the guest list/withdraw,
 * guest duplicate hints, the admin settings and the public board payload,
 * so the rule cannot drift between them:
 *
 *  - instanceAllows(): the installation-wide kill switch
 *                   (Config::$guestSubmissionEnabled, off by default in
 *                   routing_mode 'cloud') — when off, every board behaves
 *                   exactly like "off", whatever its flag says;
 *  - switchedOn():  instanceAllows() AND the board flag is on AND the
 *                   account's EFFECTIVE plan allows it (a downgrade behaves
 *                   exactly like "off");
 *  - isAvailable(): switchedOn() AND Turnstile is configured on this
 *                   instance — otherwise every guest submission would fail
 *                   closed, so the SPA must not show a dead form.
 *
 * The board must already have been resolved through an account-scoped
 * BoardRepository chokepoint by the caller.
 */
final readonly class AnonymousSubmissionGate
{
    public function __construct(
        private Config $config,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
    ) {}

    public function instanceAllows(): bool
    {
        return $this->config->guestSubmissionEnabled;
    }

    public function planAllows(int $accountId): bool
    {
        $plan    = $this->plans->forAccount($accountId, null);

        return $this->planPolicy->anonymousSubmissionAllowed($plan);
    }

    public function turnstileConfigured(): bool
    {
        return $this->config->turnstileConfigured();
    }

    /** The public Turnstile site key the guest form renders its widget with. */
    public function turnstileSiteKey(): string
    {
        return $this->config->turnstileSiteKey();
    }

    /** @param array<string, mixed> $board */
    public function switchedOn(array $board, int $accountId): bool
    {
        return $this->config->guestSubmissionEnabled
            && (bool) ($board['anonymous_submission_enabled'] ?? false)
            && $this->planAllows($accountId);
    }

    /** @param array<string, mixed> $board */
    public function isAvailable(array $board, int $accountId): bool
    {
        return $this->switchedOn($board, $accountId)
            && $this->turnstileConfigured();
    }
}
