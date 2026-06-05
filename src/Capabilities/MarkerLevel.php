<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Capabilities;

/**
 * One supply/marker level (ink or toner), assembled from the index-aligned
 * `marker-*` attributes (RFC 3380 / PWG 5100.x).
 *
 * IPP allows sentinel levels: -1 / -2 mean "unknown", -3 means "unknown but
 * present". {@see isKnown()} guards those before reading {@see percentage()}.
 */
final readonly class MarkerLevel
{
    public function __construct(
        public string $name,
        public int $level,
        public ?string $type = null,
        public ?string $color = null,
        public int $lowLevel = 0,
        public int $highLevel = 100,
    ) {}

    public function isKnown(): bool
    {
        return $this->level >= 0;
    }

    /**
     * Level as a 0–100 percentage of the marker's reported maximum, or null
     * when the printer reports an unknown level.
     */
    public function percentage(): ?int
    {
        if (! $this->isKnown()) {
            return null;
        }

        if ($this->highLevel <= 0) {
            return $this->level;
        }

        return (int) round($this->level / $this->highLevel * 100);
    }

    /**
     * Whether the marker is at or below its low-level threshold.
     */
    public function isLow(): bool
    {
        return $this->isKnown() && $this->lowLevel > 0 && $this->level <= $this->lowLevel;
    }
}
