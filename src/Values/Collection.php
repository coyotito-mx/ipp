<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Values;

use Coyotito\Ipp\Protocol\Attribute;

/**
 * A `collection` value (RFC 8010 §3.5.2 / RFC 3382): an ordered set of member
 * attributes. Members are plain {@see Attribute}s and may themselves be
 * collections, allowing arbitrary nesting (e.g. `media-col`).
 */
final readonly class Collection
{
    /**
     * @param  array<int,Attribute>  $members
     */
    public function __construct(
        public array $members = [],
    ) {}

    public function get(string $name): ?Attribute
    {
        foreach ($this->members as $member) {
            if ($member->name === $name) {
                return $member;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) instanceof Attribute;
    }

    /**
     * Flatten members to a name => value(s) map. Single-valued members collapse
     * to a scalar; nested collections recurse.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->members as $member) {
            $values = array_map(
                static fn (mixed $v): mixed => $v instanceof self ? $v->toArray() : $v,
                $member->values,
            );

            $out[$member->name] = count($values) === 1 ? $values[0] : $values;
        }

        return $out;
    }
}
