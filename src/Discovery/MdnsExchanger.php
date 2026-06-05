<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

use Coyotito\Ipp\Exceptions\DiscoveryException;

/**
 * Sends an mDNS query packet and collects the raw response packets that arrive
 * within the timeout. The seam that lets {@see MdnsDiscovery} be tested with
 * canned packets instead of real multicast traffic.
 */
interface MdnsExchanger
{
    /**
     * @param  array<int,string>  $queries  raw DNS query packets to send before listening
     * @return array<int,string> raw DNS response packets received within the window
     *
     * @throws DiscoveryException
     */
    public function query(array $queries, int $timeoutSeconds = 5): array;
}
