<?php

declare(strict_types=1);

use Coyotito\Ipp\Discovery\MdnsDiscovery;
use Coyotito\Ipp\Discovery\MdnsExchanger;

/** Encode a DNS name (uncompressed) for fixture packets. */
function dnsName(string $name): string
{
    $out = '';
    foreach (explode('.', trim($name, '.')) as $label) {
        $out .= chr(strlen($label)).$label;
    }

    return $out."\x00";
}

/** Build a minimal mDNS response packet with SRV + TXT records for one printer. */
function mdnsPacket(string $instance, string $target, int $port, string $rp): string
{
    $srvRdata = pack('nnn', 0, 0, $port).dnsName($target);
    $srv = dnsName($instance).pack('nnNn', 33, 0x8001, 120, strlen($srvRdata)).$srvRdata;

    $txtEntries = '';
    foreach (["rp={$rp}", 'ty=Test Printer'] as $entry) {
        $txtEntries .= chr(strlen($entry)).$entry;
    }
    $txt = dnsName($instance).pack('nnNn', 16, 0x8001, 120, strlen($txtEntries)).$txtEntries;

    // header: id=0, flags=0x8400 (response), qd=0, an=2, ns=0, ar=0
    return pack('n6', 0, 0x8400, 0, 2, 0, 0).$srv.$txt;
}

/** Returns canned packets regardless of the queries sent. */
final class FakeMdnsExchanger implements MdnsExchanger
{
    /** @param array<int,string> $packets */
    public function __construct(private readonly array $packets) {}

    public array $lastQueries = [];

    public function query(array $queries, int $timeoutSeconds = 5): array
    {
        $this->lastQueries = $queries;

        return $this->packets;
    }
}

it('builds a PTR query with the unicast-response bit set', function () {
    $query = MdnsDiscovery::buildQuery('_ipp._tcp.local');

    // header (12) + name + qtype(2) + qclass(2)
    expect(substr($query, 0, 12))->toBe(pack('n6', 0, 0, 1, 0, 0, 0))
        ->and(str_contains($query, "\x04_ipp\x04_tcp\x05local\x00"))->toBeTrue();

    $qclass = unpack('n', substr($query, -2))[1];
    expect($qclass)->toBe(0x8001); // IN + QU bit
});

it('parses SRV/TXT records into a discovered printer', function () {
    $packet = mdnsPacket('Office Printer._ipp._tcp.local', 'office-printer.local', 631, 'ipp/print');

    $printers = MdnsDiscovery::parse($packet);

    expect($printers)->toHaveCount(1)
        ->and($printers[0]->uri)->toBe('ipp://office-printer.local:631/ipp/print')
        ->and($printers[0]->host)->toBe('office-printer.local')
        ->and($printers[0]->port)->toBe(631)
        ->and($printers[0]->scheme)->toBe('ipp')
        ->and($printers[0]->name)->toBe('Office Printer');
});

it('derives the ipps scheme from an _ipps service instance', function () {
    $packet = mdnsPacket('Office._ipps._tcp.local', 'office.local', 443, 'ipp/print');

    $printer = MdnsDiscovery::parse($packet)[0];

    expect($printer->scheme)->toBe('ipps')
        ->and($printer->isSecure())->toBeTrue()
        ->and($printer->uri)->toBe('ipps://office.local:443/ipp/print');
});

it('discovers via the exchanger and de-duplicates across packets', function () {
    $packet = mdnsPacket('A._ipp._tcp.local', 'a.local', 631, 'ipp/print');
    $exchanger = new FakeMdnsExchanger([$packet, $packet]); // same printer twice

    $printers = (new MdnsDiscovery($exchanger))->discover();

    expect($printers)->toHaveCount(1)
        ->and($printers[0]->host)->toBe('a.local')
        // both _ipp and _ipps queries were sent
        ->and($exchanger->lastQueries)->toHaveCount(2);
});

it('returns nothing for a truncated packet', function () {
    expect(MdnsDiscovery::parse("\x00\x00"))->toBe([]);
});
