<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * The branding one board actually shows, after its own values and the
 * CURRENT plan were applied (BrandResolver). Only validated values are ever
 * held here; a null field means "not applied".
 *
 * The logo is one of two things, in this order: an uploaded logo (`logoPublicId`,
 * the opaque id of the stored 256x256 rendition, served by the gated public
 * asset endpoint) or the legacy link (`logoUrl`, shown only while no upload
 * exists). Never both.
 */
final readonly class ResolvedBrand
{
    public function __construct(
        public ?string $primaryColor,
        public ?string $secondaryColor,
        public ?string $logoUrl,
        public ?string $logoPublicId = null,
    ) {}
}
