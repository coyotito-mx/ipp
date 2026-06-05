<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Operations;

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Exceptions\IppException;
use Coyotito\Ipp\Protocol\Attribute;
use Coyotito\Ipp\Protocol\AttributeGroup;
use Coyotito\Ipp\Values\IntegerRange;

/**
 * A fluent builder for the job-attributes group of a Print-Job / Validate-Job
 * request (RFC 8011 §5.2). Maps friendly options to standard IPP Job Template
 * attributes (`copies`, `print-color-mode`, `sides`, `media`, `page-ranges`).
 *
 * Named for what it carries — the print options — rather than the IPP "Job"
 * object (which the facade returns from a submission as a separate concept).
 */
final class PrintOptions
{
    /** @var array<int,Attribute> */
    private array $attributes = [];

    public static function make(): self
    {
        return new self;
    }

    public function copies(int $count): self
    {
        if ($count < 1) {
            throw new IppException("copies must be at least 1, got {$count}.");
        }

        return $this->set('copies', ValueTag::Integer, $count);
    }

    public function color(): self
    {
        return $this->set('print-color-mode', ValueTag::Keyword, 'color');
    }

    public function monochrome(): self
    {
        return $this->set('print-color-mode', ValueTag::Keyword, 'monochrome');
    }

    public function sides(string $sides): self
    {
        return $this->set('sides', ValueTag::Keyword, $sides);
    }

    public function simplex(): self
    {
        return $this->sides('one-sided');
    }

    public function duplex(bool $longEdge = true): self
    {
        return $this->sides($longEdge ? 'two-sided-long-edge' : 'two-sided-short-edge');
    }

    /**
     * Set the media by PWG self-describing name, e.g. `na_legal_8.5x14in`,
     * `na_letter_8.5x11in`, `iso_a4_210x297mm`.
     */
    public function media(string $media): self
    {
        return $this->set('media', ValueTag::Keyword, $media);
    }

    /**
     * Restrict printing to a set of page ranges, e.g. `1-5`, `2`, `1-3,7,9-10`.
     * Encoded as a 1setOf rangeOfInteger.
     */
    public function pages(string $ranges): self
    {
        $values = [];

        foreach (explode(',', $ranges) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (str_contains($part, '-')) {
                [$lower, $upper] = array_map('trim', explode('-', $part, 2));
                $values[] = new IntegerRange((int) $lower, (int) $upper);

                continue;
            }

            $values[] = new IntegerRange((int) $part, (int) $part);
        }

        if ($values === []) {
            throw new IppException("Could not parse page ranges from '{$ranges}'.");
        }

        return $this->replace(new Attribute('page-ranges', ValueTag::RangeOfInteger, $values));
    }

    /**
     * Set the print quality: 3 = draft, 4 = normal, 5 = high (enum, §5.2.13).
     */
    public function quality(int $quality): self
    {
        return $this->set('print-quality', ValueTag::Enum, $quality);
    }

    /**
     * Attach an arbitrary job-template attribute not covered by the helpers.
     */
    public function attribute(Attribute $attribute): self
    {
        return $this->replace($attribute);
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }

    /**
     * Build the job-attributes group (empty if no options were set).
     */
    public function toGroup(): AttributeGroup
    {
        return new AttributeGroup(DelimiterTag::JobAttributes, array_values($this->attributes));
    }

    private function set(string $name, ValueTag $tag, mixed $value): self
    {
        return $this->replace(Attribute::of($name, $tag, $value));
    }

    private function replace(Attribute $attribute): self
    {
        $this->attributes[$attribute->name] = $attribute;

        return $this;
    }
}
