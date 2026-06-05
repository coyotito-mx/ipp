<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

use Coyotito\Ipp\Exceptions\DiscoveryException;
use Socket\Raw\Exception as SocketException;
use Socket\Raw\Factory;
use Socket\Raw\Socket;

/**
 * Default {@see MdnsExchanger}, built on clue/socket-raw (a thin OO wrapper over
 * ext-sockets). Sends the queries to the mDNS multicast group
 * (224.0.0.251:5353) with the QU bit set, so printers reply by unicast to our
 * ephemeral port — simpler and more reliable than joining the group to listen.
 *
 * On Windows, allow inbound UDP for the app in the firewall, or fall back to
 * {@see IppFindDiscovery}/manual entry.
 */
final class SocketMdnsExchanger implements MdnsExchanger
{
    private const string MDNS_TARGET = '224.0.0.251:5353';

    public function __construct(
        private readonly Factory $factory = new Factory,
    ) {}

    public function query(array $queries, int $timeoutSeconds = 5): array
    {
        $socket = $this->open();

        try {
            foreach ($queries as $query) {
                $socket->sendTo($query, 0, self::MDNS_TARGET);
            }

            return $this->collect($socket, $timeoutSeconds);
        } catch (SocketException $e) {
            throw new DiscoveryException("mDNS query failed: {$e->getMessage()}.", previous: $e);
        } finally {
            $socket->close();
        }
    }

    private function open(): Socket
    {
        try {
            $socket = $this->factory->createUdp4();
            $socket->setOption(IPPROTO_IP, IP_MULTICAST_TTL, 4);
            $socket->bind('0.0.0.0:0');

            return $socket;
        } catch (SocketException $e) {
            throw new DiscoveryException("Could not open an mDNS socket: {$e->getMessage()}.", previous: $e);
        }
    }

    /**
     * @return array<int,string>
     */
    private function collect(Socket $socket, int $timeoutSeconds): array
    {
        $packets = [];
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0 || ! $socket->selectRead($remaining)) {
                break; // window elapsed or no more replies
            }

            $remote = null;
            $data = $socket->recvFrom(9000, 0, $remote);

            if ($data === '') {
                break;
            }

            $packets[] = $data;
        }

        return $packets;
    }
}
