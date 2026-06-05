<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

/**
 * Finds IPP printers on the local network, plus manual registration by IP.
 *
 * Pick a strategy by platform:
 *  - {@see ippfind()} — CUPS' `ippfind` (macOS/Linux). Default.
 *  - {@see mdns()}    — pure-PHP mDNS (also Windows; needs ext-sockets).
 *
 * When discovery is unavailable (firewall, no mDNS), {@see manual()} builds a
 * printer from a known host/IP — always works.
 */
final class Discovery
{
    public function __construct(
        private readonly DiscoveryStrategy $strategy = new IppFindDiscovery,
    ) {}

    public static function ippfind(string $binary = 'ippfind'): self
    {
        return new self(new IppFindDiscovery(binary: $binary));
    }

    public static function mdns(): self
    {
        return new self(new MdnsDiscovery);
    }

    public static function using(DiscoveryStrategy $strategy): self
    {
        return new self($strategy);
    }

    /**
     * Browse for printers (de-duplicated by URI) over a $timeout-second window.
     *
     * @return array<int,DiscoveredPrinter>
     */
    public function discover(int $timeout = 5): array
    {
        return $this->strategy->discover($timeout);
    }

    /**
     * Register a printer manually by host/IP (fallback when mDNS is off).
     */
    public function manual(
        string $host,
        int $port = 631,
        string $path = '/ipp/print',
        bool $secure = false,
        ?string $name = null,
    ): DiscoveredPrinter {
        $scheme = $secure ? 'ipps' : 'ipp';
        $path = str_starts_with($path, '/') ? $path : "/{$path}";

        return new DiscoveredPrinter("{$scheme}://{$host}:{$port}{$path}", $host, $port, $scheme, $name);
    }
}
