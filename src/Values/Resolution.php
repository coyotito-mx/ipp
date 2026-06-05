<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Values;

use Coyotito\Ipp\Enums\ResolutionUnit;

/**
 * A `resolution` value (RFC 8010 §3.5.2): cross-feed and feed resolutions
 * plus a unit (dpi or dpcm).
 */
final readonly class Resolution
{
    public function __construct(
        public int $crossFeed,
        public int $feed,
        public ResolutionUnit $units = ResolutionUnit::DotsPerInch,
    ) {}

    public function __toString(): string
    {
        $suffix = $this->units === ResolutionUnit::DotsPerInch ? 'dpi' : 'dpcm';

        return "{$this->crossFeed}x{$this->feed}{$suffix}";
    }
}
