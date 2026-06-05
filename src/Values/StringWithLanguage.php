<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Values;

/**
 * A `textWithLanguage` / `nameWithLanguage` value (RFC 8010 §3.5.2): a string
 * paired with its natural language (e.g. "es-mx"). The owning attribute's
 * value tag distinguishes text from name.
 */
final readonly class StringWithLanguage
{
    public function __construct(
        public string $value,
        public string $language,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
