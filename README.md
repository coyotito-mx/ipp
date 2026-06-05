# coyotito/ipp

A clean, standards-compliant **IPP (Internet Printing Protocol)** client for PHP.
Discover network printers, read their capabilities, and submit print jobs —
vendor and model agnostic, conformant to **RFC 8011** (model/semantics) and
**RFC 8010** (encoding/transport).

No CUPS required: it speaks IPP directly over HTTP(S) to port 631.

## Requirements

- PHP **8.4+**
- [`guzzlehttp/guzzle`](https://github.com/guzzle/guzzle) — HTTP transport (swappable; any PSR-18 client works)
- [`symfony/process`](https://github.com/symfony/process) — running `ippfind` for discovery
- [`clue/socket-raw`](https://github.com/clue/socket-raw) — pure-PHP mDNS discovery (needs `ext-sockets`)

## Installation

```bash
composer require coyotito/ipp
```

## Quick start

```php
use Coyotito\Ipp\Printer;

$printer = Printer::connect('ipps://192.168.1.50:631/ipp/print');

// What can it do? (Get-Printer-Attributes → typed view)
$caps = $printer->capabilities();
$caps->makeAndModel();        // "Office Printer"
$caps->isOnline();            // true
$caps->hasMediaReady('na_legal_8.5x14in');
$caps->supportsColor();
$caps->supportsDuplex();

// Print, fluently. Nothing is sent until ->send().
$job = $printer->print($bytes, 'image/jpeg')
    ->copies(2)
    ->color()
    ->media('na_letter_8.5x11in')
    ->duplex()
    ->pages('1-5')
    ->send();

$job->id;            // 14
$job->status();      // JobState::Processing → Completed (re-fetched live)
$job->isCompleted();
$job->cancel();
```

## Discovery

```php
use Coyotito\Ipp\Discovery\Discovery;

foreach (Discovery::mdns()->discover(timeout: 5) as $printer) {
    echo $printer->uri;   // ipp://office-printer.local:631/ipp/print
    echo $printer->name;  // "Office Printer"
}

// Always-available fallback: register by IP/host.
$printer = Discovery::mdns()->manual('192.168.1.50', secure: true);
```

### Platform support

Discovery has two interchangeable strategies; pick what fits the host OS:

| Strategy | How | macOS | Linux | Windows |
|---|---|:--:|:--:|:--:|
| `Discovery::ippfind()` | CUPS' `ippfind` (wraps Bonjour/`dns-sd` & Avahi) | ✅ built-in | ✅ via CUPS | ❌ no CUPS by default |
| `Discovery::mdns()` | pure-PHP multicast DNS (`clue/socket-raw`) | ✅ | ✅ | ✅ |
| `…->manual(host)` | known IP/host, no discovery | ✅ | ✅ | ✅ |

**On Windows**, use `Discovery::mdns()` — there is no `ippfind`. Make sure
`ext-sockets` is enabled and allow the app inbound UDP in Windows Firewall, or
fall back to `manual()`. (In an Electron/NativePHP app you can also discover with
a Node mDNS library and feed the URI straight into `Printer::connect()`.)

Both strategies, and the underlying command/socket runners, are interfaces —
swap or fake them freely.

## Transport

The default transport is Guzzle, but it only depends on the PSR-18
`ClientInterface`, so you can inject any client (or a Guzzle `MockHandler` in
tests):

```php
use Coyotito\Ipp\Transport\GuzzleTransport;

$printer = Printer::connect($uri, new GuzzleTransport(
    client: $myPsr18Client,   // optional; defaults to a configured Guzzle client
    username: 'admin',        // optional Basic auth
    password: 'secret',
    verifyTls: false,         // printers ship self-signed certs (default)
));
```

Notes for real network printers:

- `ipp://` maps to http, `ipps://` to https (port 631 by default).
- TLS verification is **off by default** (self-signed certs are the norm).
- On a `426 Upgrade Required` over cleartext, the transport retries once over
  TLS. Still, **prefer the `ipps://` URI for large jobs** — some printers reject
  large cleartext uploads outright instead of asking to upgrade.

## Printers don't always accept PDF

Many printers (most inkjets) do **not** list `application/pdf` in
`document-format-supported` — they expect raster. Decide per printer:

```php
$caps = $printer->capabilities();

$format = $caps->preferredFormat([
    'application/pdf',     // send as-is if supported
    'image/pwg-raster',    // else rasterize to PWG Raster (IPP Everywhere)
    'image/urf',           // or Apple Raster (AirPrint)
    'image/jpeg',          // last resort
]);

$caps->pwgRasterResolutions();   // resolutions valid for raster output
$caps->printQualitiesSupported();
```

Rasterizing PDF → PWG Raster/URF is your application's job (e.g. Ghostscript /
`mutool` / CUPS filters); this package reports what the printer accepts and ships
the bytes you give it with the right `document-format`.

## Supported operations

`Get-Printer-Attributes`, `Print-Job`, `Validate-Job`, `Create-Job`,
`Send-Document`, `Get-Job-Attributes`, `Get-Jobs`, `Cancel-Job`. The wire format
supports every IPP value syntax, including 1setOf values and nested collections
(`media-col`).

## Conformance & testing

- Strict to **RFC 8011** and **RFC 8010**: `version 2.0`; operation-attributes
  first, with `attributes-charset` (utf-8) and `attributes-natural-language` as
  the first two attributes; correct `printer-uri` / `requesting-user-name` /
  `document-format`; full status-code handling.
- Encoder/decoder round-trip tests, a binary response fixture, and cross-checked
  against `ipptool` and a real printer.

```bash
composer test
```

## License

MIT.
