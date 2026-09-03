<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * Inline image of an HTML mail (CID embedding).
 *
 * Describes an image that the HTML part references via
 * `<img src="cid:{cid}">` and that the sending adapter embeds as an inline
 * attachment (multipart/related). CID embedding is the most broadly
 * compatible way to include logos in transactional mail — `data:` URIs are
 * blocked by some Outlook versions, and external image URLs aren't loaded
 * by many clients until the user allows it.
 *
 * Pure value object: the path is NOT read here — only SymfonyMailerAdapter
 * reads it, at actual send time (InMemoryMailer in tests never needs to
 * touch the file).
 */
final readonly class InlineImage
{
    public function __construct(
        public string $cid,
        public string $path,
    ) {}
}
