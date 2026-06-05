<?php

declare(strict_types=1);

use Coyotito\Ipp\Exceptions\TransportException;
use Coyotito\Ipp\Transport\IppUri;

it('maps ipp:// to http on port 631', function () {
    expect(IppUri::toHttpUrl('ipp://printer.local:631/ipp/print'))
        ->toBe('http://printer.local:631/ipp/print');
});

it('maps ipps:// to https and defaults the port to 631', function () {
    expect(IppUri::toHttpUrl('ipps://printer.local/ipp/print'))
        ->toBe('https://printer.local:631/ipp/print');
});

it('preserves an explicit port and query string', function () {
    expect(IppUri::toHttpUrl('ipps://printer.local:443/ipp/print?version=2.0'))
        ->toBe('https://printer.local:443/ipp/print?version=2.0');
});

it('defaults the path to / when absent', function () {
    expect(IppUri::toHttpUrl('ipp://printer.local'))
        ->toBe('http://printer.local:631/');
});

it('rejects a URI without a host', function () {
    expect(fn () => IppUri::toHttpUrl('not a uri'))
        ->toThrow(TransportException::class);
});
