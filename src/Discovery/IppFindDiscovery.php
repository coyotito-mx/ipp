<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

/**
 * Discovery via CUPS' `ippfind`, which wraps the per-OS mDNS resolver
 * (Bonjour/`dns-sd` on macOS, Avahi on Linux) and prints `ipp://` / `ipps://`
 * URIs. Not available on Windows by default (no CUPS) — use {@see MdnsDiscovery}
 * there.
 */
final class IppFindDiscovery implements DiscoveryStrategy
{
    public function __construct(
        private readonly CommandRunner $runner = new ProcessCommandRunner,
        private readonly string $binary = 'ippfind',
    ) {}

    public function discover(int $timeout = 5): array
    {
        $command = [$this->binary];

        if ($timeout > 0) {
            $command[] = '-T';
            $command[] = (string) $timeout;
        }

        return self::parse($this->runner->run($command, $timeout + 2));
    }

    /**
     * @return array<int,DiscoveredPrinter>
     */
    public static function parse(string $output): array
    {
        $printers = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, 'ipp')) {
                continue;
            }

            $printers[$line] = DiscoveredPrinter::fromUri($line);
        }

        return array_values($printers);
    }
}
