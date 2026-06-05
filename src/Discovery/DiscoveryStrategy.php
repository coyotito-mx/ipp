<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

use Coyotito\Ipp\Exceptions\DiscoveryException;

/**
 * A way to find IPP printers on the network. Implementations differ by
 * platform: {@see IppFindDiscovery} shells out to CUPS' `ippfind`
 * (macOS/Linux), while {@see MdnsDiscovery} speaks mDNS directly in pure PHP
 * (works on Windows too, no external binary).
 */
interface DiscoveryStrategy
{
    /**
     * @return array<int,DiscoveredPrinter>
     *
     * @throws DiscoveryException
     */
    public function discover(int $timeout = 5): array;
}
