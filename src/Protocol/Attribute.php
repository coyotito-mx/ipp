<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Protocol;

use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Values\Collection;
use Coyotito\Ipp\Values\DateTimeValue;
use Coyotito\Ipp\Values\IntegerRange;
use Coyotito\Ipp\Values\Resolution;
use Coyotito\Ipp\Values\StringWithLanguage;

/**
 * A single IPP attribute: a name, a value tag describing the syntax, and one
 * or more values (a "1setOf" when more than one). Out-of-band attributes
 * (unsupported / unknown / no-value) carry an empty value list.
 *
 * Native PHP value mapping by tag:
 *  - integer / enum                 → int
 *  - boolean                        → bool
 *  - text / name / keyword / uri /
 *    charset / naturalLanguage /
 *    mimeMediaType / octetString    → string
 *  - resolution                     → {@see Resolution}
 *  - rangeOfInteger                 → {@see IntegerRange}
 *  - dateTime                       → {@see DateTimeValue}
 *  - text/nameWithLanguage          → {@see StringWithLanguage}
 *  - begCollection                  → {@see Collection}
 */
final readonly class Attribute
{
    /** @var array<int,mixed> */
    public array $values;

    public function __construct(
        public string $name,
        public ValueTag $tag,
        mixed $values = [],
    ) {
        $this->values = is_array($values) ? array_values($values) : [$values];
    }

    /**
     * Convenience factory for a single-valued attribute.
     */
    public static function of(string $name, ValueTag $tag, mixed $value): self
    {
        return new self($name, $tag, [$value]);
    }

    public function firstValue(): mixed
    {
        return $this->values[0] ?? null;
    }

    public function isMultiValued(): bool
    {
        return count($this->values) > 1;
    }

    public function isOutOfBand(): bool
    {
        return $this->tag->isOutOfBand();
    }
}
