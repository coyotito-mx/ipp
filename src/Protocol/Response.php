<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Protocol;

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\StatusCode;

/**
 * An IPP response message (RFC 8010 §3.1): version, status-code, request-id,
 * the attribute groups and an optional data trailer.
 *
 * The status code is kept as a raw int so that codes outside {@see StatusCode}
 * (e.g. extensions) never break decoding; {@see status()} resolves the enum
 * when known and {@see isSuccessful()} works purely from the numeric range.
 */
final class Response
{
    /**
     * @param  array<int,AttributeGroup>  $groups
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly int $requestId,
        public array $groups = [],
        public string $data = '',
        public readonly int $versionMajor = 2,
        public readonly int $versionMinor = 0,
    ) {}

    public function status(): ?StatusCode
    {
        return StatusCode::tryFrom($this->statusCode);
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode <= 0x00FF;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 0x0400 && $this->statusCode <= 0x04FF;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 0x0500 && $this->statusCode <= 0x05FF;
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

    /**
     * All attribute groups with the given delimiter tag (a Get-Jobs response,
     * for instance, repeats the job-attributes group once per job).
     *
     * @return array<int,AttributeGroup>
     */
    public function groups(DelimiterTag $tag): array
    {
        return array_values(array_filter(
            $this->groups,
            static fn (AttributeGroup $group): bool => $group->tag === $tag,
        ));
    }

    public function printerAttributes(): ?AttributeGroup
    {
        return $this->group(DelimiterTag::PrinterAttributes);
    }
}
