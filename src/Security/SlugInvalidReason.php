<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Distinguishable rejection reasons returned by {@see SlugValidator::validate()}.
 */
enum SlugInvalidReason
{
    case InvalidLength;
    case InvalidCharacters;
    case LeadingHyphen;
    case TrailingHyphen;
    case DoubleHyphen;
    case ReservedWord;
}
