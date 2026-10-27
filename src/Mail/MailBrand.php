<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Votepit\Security\BrandingValidator;

/**
 * Colours of a branded board mail (P-32, Pro). Only validated hex values are
 * ever held here, and the call-to-action colour must keep WCAG AA contrast
 * with the white button text, otherwise the Votepit default is used.
 *
 * No customer logo is ever embedded: the mail keeps the Votepit lockup, the
 * customer's name is the sender display name. An arbitrary external logo URL
 * would be a tracking and spoofing vector inside mail clients.
 */
final readonly class MailBrand
{
    private function __construct(
        public string $ruleColor,
        public string $ctaColor,
    ) {}

    /**
     * @param string|null $primary   board/account primary colour (header rule)
     * @param string|null $secondary board/account secondary colour (button); falls back to primary
     */
    public static function fromColors(?string $primary, ?string $secondary, string $defaultRule, string $defaultCta): self
    {
        $rule = $primary !== null ? BrandingValidator::color($primary) : null;

        $cta = null;
        foreach ([$secondary, $primary] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            // secondaryColor() is the raw colour-against-white check: the button
            // is always paired with white text.
            $valid = BrandingValidator::secondaryColor($candidate);
            if ($valid !== null) {
                $cta = $valid;
                break;
            }
        }

        return new self($rule ?? $defaultRule, $cta ?? $defaultCta);
    }
}
