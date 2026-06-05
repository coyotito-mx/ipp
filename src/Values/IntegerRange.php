<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Values;

/**
 * A `rangeOfInteger` value (RFC 8010 §3.5.2): an inclusive lower/upper bound,
 * used by attributes such as `copies-supported` and `page-ranges`.
 */
final readonly class IntegerRange
{
    public function __construct(
        public int $lower,
        public int $upper,
    ) {}

    public function contains(int $value): bool
    {
        return $value >= $this->lower && $value <= $this->upper;
    }

    public function __toString(): string
    {
        return "{$this->lower}-{$this->upper}";
    }
}
