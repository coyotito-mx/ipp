<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

use Coyotito\Ipp\Exceptions\DiscoveryException;

/**
 * A printer found on the network (or registered manually): its IPP URI broken
 * into the parts needed to connect.
 */
final readonly class DiscoveredPrinter
{
    public function __construct(
        public string $uri,
        public string $host,
        public int $port,
        public string $scheme,
        public ?string $name = null,
    ) {}

    public static function fromUri(string $uri, ?string $name = null): self
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['host'])) {
            throw new DiscoveryException("Cannot parse printer URI: {$uri}.");
        }

        $scheme = strtolower($parts['scheme'] ?? 'ipp');

        return new self(
            uri: $uri,
            host: $parts['host'],
            port: $parts['port'] ?? 631,
            scheme: $scheme,
            name: $name,
        );
    }

    public function isSecure(): bool
    {
        return $this->scheme === 'ipps';
    }
}
