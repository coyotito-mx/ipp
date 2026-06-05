<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

use Coyotito\Ipp\Exceptions\DiscoveryException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Default {@see CommandRunner} backed by Symfony Process — handles argv
 * escaping, cross-platform spawning and timeouts (no `timeout(1)` needed).
 */
final class ProcessCommandRunner implements CommandRunner
{
    /** Exit status POSIX shells use for "command not found". */
    private const int EXIT_NOT_FOUND = 127;

    public function run(array $command, int $timeoutSeconds = 6): string
    {
        $process = new Process($command);
        $process->setTimeout((float) $timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // The browse window elapsed; return whatever was collected so far.
            return $process->getOutput();
        } catch (ProcessStartFailedException $e) {
            throw new DiscoveryException("Could not start: {$command[0]} ({$e->getMessage()}).", previous: $e);
        }

        if ($process->getExitCode() === self::EXIT_NOT_FOUND) {
            throw new DiscoveryException("Command not found: {$command[0]}.");
        }

        return $process->getOutput();
    }
}
