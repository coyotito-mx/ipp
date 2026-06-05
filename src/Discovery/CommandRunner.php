<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

use Coyotito\Ipp\Exceptions\DiscoveryException;

/**
 * Runs an external command and returns its stdout. The seam that lets
 * {@see Discovery} stay testable without touching the network or mDNS.
 */
interface CommandRunner
{
    /**
     * @param  array<int,string>  $command  argv (no shell), e.g. ['ippfind', '-T', '5']
     * @return string captured stdout
     *
     * @throws DiscoveryException when the command cannot be started or is not installed
     */
    public function run(array $command, int $timeoutSeconds = 6): string;
}
