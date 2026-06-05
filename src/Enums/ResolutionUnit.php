<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Enums;

/**
 * Units byte of a `resolution` value (RFC 8010 §3.5.2 / RFC 8011).
 */
enum ResolutionUnit: int
{
    case DotsPerInch = 3;
    case DotsPerCentimeter = 4;
}
