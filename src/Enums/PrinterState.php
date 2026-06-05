<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Enums;

/**
 * `printer-state` enum values (RFC 8011 §5.4.11).
 */
enum PrinterState: int
{
    case Idle = 3;
    case Processing = 4;
    case Stopped = 5;

    public function keyword(): string
    {
        return match ($this) {
            self::Idle => 'idle',
            self::Processing => 'processing',
            self::Stopped => 'stopped',
        };
    }
}
