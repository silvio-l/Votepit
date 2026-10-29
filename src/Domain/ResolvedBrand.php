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
    /**
     * @param ?string $accentColor highlight colour (accent_color, paid plans), null = primary
     * @param ?string $primaryColorDark dark-mode primary (paid plans), null = derived
     * @param ?string $accentColorDark dark-mode accent (paid plans), null = derived
     * @param ?string $logoPlate BoardLogoPlate value, null = auto (also when not applied)
     * @param bool $logoRingHidden true = no ring around the round logo
     */
    public function __construct(
        public ?string $primaryColor,
        public ?string $secondaryColor,
        public ?string $logoUrl,
        public ?string $logoPublicId = null,
        public ?string $accentColor = null,
        public ?string $primaryColorDark = null,
        public ?string $accentColorDark = null,
        public ?string $logoPlate = null,
        public bool $logoRingHidden = false,
    ) {}
}
