<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Values;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * A `dateTime` value (RFC 8010 §3.5.2, encoded as the RFC 2579 DateAndTime
 * 11-octet structure). Wraps a {@see DateTimeImmutable} and keeps deci-second
 * precision plus the UTC offset.
 */
final readonly class DateTimeValue
{
    public function __construct(
        public DateTimeImmutable $value,
    ) {}

    public static function fromInterface(DateTimeInterface $value): self
    {
        return new self(DateTimeImmutable::createFromInterface($value));
    }

    public function __toString(): string
    {
        return $this->value->format(DateTimeInterface::ATOM);
    }
}
