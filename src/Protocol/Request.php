<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Protocol;

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\Operation;

/**
 * An IPP request message (RFC 8010 §3.1): version, operation-id, request-id,
 * the attribute groups and an optional document data trailer.
 */
final class Request
{
    /**
     * @param  array<int,AttributeGroup>  $groups
     */
    public function __construct(
        public readonly Operation $operation,
        public readonly int $requestId,
        public array $groups = [],
        public string $data = '',
        public readonly int $versionMajor = 2,
        public readonly int $versionMinor = 0,
    ) {}

    public function addGroup(AttributeGroup $group): static
    {
        $this->groups[] = $group;

        return $this;
    }

    public function group(DelimiterTag $tag): ?AttributeGroup
    {
        foreach ($this->groups as $group) {
            if ($group->tag === $tag) {
                return $group;
            }
        }

        return null;
    }
}
