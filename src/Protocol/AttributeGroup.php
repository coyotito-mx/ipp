<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Protocol;

use Coyotito\Ipp\Enums\DelimiterTag;

/**
 * An IPP attribute group: a delimiter tag (operation / job / printer / …)
 * followed by its attributes, in wire order.
 */
final class AttributeGroup
{
    /**
     * @param  array<int,Attribute>  $attributes
     */
    public function __construct(
        public readonly DelimiterTag $tag,
        public array $attributes = [],
    ) {}

    public function add(Attribute $attribute): static
    {
        $this->attributes[] = $attribute;

        return $this;
    }

    public function get(string $name): ?Attribute
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->name === $name) {
                return $attribute;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) instanceof Attribute;
    }

    /**
     * @return array<int,Attribute>
     */
    public function all(): array
    {
        return $this->attributes;
    }
}
