<?php

declare(strict_types=1);

use Coyotito\Ipp\Discovery\CommandRunner;
use Coyotito\Ipp\Discovery\DiscoveredPrinter;
use Coyotito\Ipp\Discovery\Discovery;
use Coyotito\Ipp\Discovery\DiscoveryStrategy;
use Coyotito\Ipp\Discovery\IppFindDiscovery;

/**
 * Captures the command and returns canned stdout, so ippfind parsing is tested
 * without mDNS or the network.
 */
final class FakeRunner implements CommandRunner
{
    /** @var array<int,string> */
    public array $lastCommand = [];

    public function __construct(private readonly string $output) {}

    public function run(array $command, int $timeoutSeconds = 6): string
    {
        $this->lastCommand = $command;

        return $this->output;
    }
}

it('parses ippfind output into discovered printers', function () {
    $output = <<<'OUT'
    ipp://EPSONEAC7AB.local:631/ipp/print
    ipps://officejet.local:631/ipp/print

    OUT;

    $printers = (new IppFindDiscovery(new FakeRunner($output)))->discover();

    expect($printers)->toHaveCount(2)
        ->and($printers[0]->host)->toBe('EPSONEAC7AB.local')
        ->and($printers[0]->port)->toBe(631)
        ->and($printers[0]->scheme)->toBe('ipp')
        ->and($printers[1]->scheme)->toBe('ipps')
        ->and($printers[1]->isSecure())->toBeTrue();
});

it('de-duplicates repeated URIs and ignores noise lines', function () {
    $output = "ipp://a.local:631/ipp/print\nScanning ...\nipp://a.local:631/ipp/print\n";

    $printers = (new IppFindDiscovery(new FakeRunner($output)))->discover();

    expect($printers)->toHaveCount(1)
        ->and($printers[0]->host)->toBe('a.local');
});

it('passes the browse timeout to ippfind', function () {
    $runner = new FakeRunner('');
    (new IppFindDiscovery($runner))->discover(timeout: 8);

    expect($runner->lastCommand)->toBe(['ippfind', '-T', '8']);
});

it('delegates discover() to the configured strategy', function () {
    $strategy = new class implements DiscoveryStrategy
    {
        public function discover(int $timeout = 5): array
        {
            return [DiscoveredPrinter::fromUri('ipp://fake.local:631/ipp/print', 'Fake')];
        }
    };

    $printers = Discovery::using($strategy)->discover();

    expect($printers)->toHaveCount(1)
        ->and($printers[0]->name)->toBe('Fake');
});

it('registers a printer manually by host', function () {
    $discovery = new Discovery(new IppFindDiscovery(new FakeRunner('')));

    $plain = $discovery->manual('192.168.1.50');
    expect($plain->uri)->toBe('ipp://192.168.1.50:631/ipp/print')
        ->and($plain->scheme)->toBe('ipp');

    $secure = $discovery->manual('192.168.1.51', port: 443, path: 'ipp/print', secure: true, name: 'Front desk');
    expect($secure->uri)->toBe('ipps://192.168.1.51:443/ipp/print')
        ->and($secure->isSecure())->toBeTrue()
        ->and($secure->name)->toBe('Front desk');
});
