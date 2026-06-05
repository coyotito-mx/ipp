<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Enums;

/**
 * `job-state` enum values (RFC 8011 §5.3.7).
 */
enum JobState: int
{
    case Pending = 3;
    case PendingHeld = 4;
    case Processing = 5;
    case ProcessingStopped = 6;
    case Canceled = 7;
    case Aborted = 8;
    case Completed = 9;

    public function keyword(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::PendingHeld => 'pending-held',
            self::Processing => 'processing',
            self::ProcessingStopped => 'processing-stopped',
            self::Canceled => 'canceled',
            self::Aborted => 'aborted',
            self::Completed => 'completed',
        };
    }

    /**
     * Terminal states: the job will not change further (RFC 8011 §5.3.7).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Canceled, self::Aborted, self::Completed => true,
            default => false,
        };
    }
}
