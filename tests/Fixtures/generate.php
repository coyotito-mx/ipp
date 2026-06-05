<?php

declare(strict_types=1);

/**
 * Regenerate the synthetic Get-Printer-Attributes response fixture.
 *
 *   php tests/Fixtures/generate.php
 *
 * The bytes mirror the shape of a real printer reply (multi-valued keywords,
 * a rangeOfInteger, a nested media-col collection) but contain no real device
 * identifiers, so the fixture is safe to commit to a public package.
 */

require __DIR__.'/../../vendor/autoload.php';

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\ResolutionUnit;
use Coyotito\Ipp\Enums\StatusCode;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Protocol\Attribute;
use Coyotito\Ipp\Protocol\AttributeGroup;
use Coyotito\Ipp\Protocol\Encoder;
use Coyotito\Ipp\Protocol\Response;
use Coyotito\Ipp\Values\Collection;
use Coyotito\Ipp\Values\IntegerRange;
use Coyotito\Ipp\Values\Resolution;

$operation = new AttributeGroup(DelimiterTag::OperationAttributes, [
    Attribute::of('attributes-charset', ValueTag::Charset, 'utf-8'),
    Attribute::of('attributes-natural-language', ValueTag::NaturalLanguage, 'en'),
]);

$mediaColReady = new Collection([
    Attribute::of('media-size', ValueTag::BegCollection, new Collection([
        Attribute::of('x-dimension', ValueTag::Integer, 21590),
        Attribute::of('y-dimension', ValueTag::Integer, 27940),
    ])),
    Attribute::of('media-type', ValueTag::Keyword, 'stationery'),
    Attribute::of('media-source', ValueTag::Keyword, 'main'),
]);

$printer = new AttributeGroup(DelimiterTag::PrinterAttributes, [
    Attribute::of('printer-name', ValueTag::NameWithoutLanguage, 'ipp/print'),
    Attribute::of('printer-make-and-model', ValueTag::TextWithoutLanguage, 'Acme LaserJet 9000'),
    Attribute::of('printer-state', ValueTag::Enum, 3), // idle
    new Attribute('printer-state-reasons', ValueTag::Keyword, ['none']),
    new Attribute('ipp-versions-supported', ValueTag::Keyword, ['1.0', '1.1', '2.0']),
    new Attribute('document-format-supported', ValueTag::MimeMediaType, [
        'application/pdf', 'application/octet-stream', 'image/jpeg', 'image/urf', 'image/pwg-raster',
    ]),
    new Attribute('media-ready', ValueTag::Keyword, ['na_letter_8.5x11in', 'na_legal_8.5x14in']),
    new Attribute('media-supported', ValueTag::Keyword, [
        'na_letter_8.5x11in', 'na_legal_8.5x14in', 'iso_a4_210x297mm',
    ]),
    new Attribute('sides-supported', ValueTag::Keyword, ['one-sided', 'two-sided-long-edge', 'two-sided-short-edge']),
    new Attribute('print-color-mode-supported', ValueTag::Keyword, ['color', 'monochrome', 'auto']),
    Attribute::of('color-supported', ValueTag::Boolean, true),
    Attribute::of('copies-supported', ValueTag::RangeOfInteger, new IntegerRange(1, 99)),
    new Attribute('printer-resolution-supported', ValueTag::Resolution, [
        new Resolution(600, 600, ResolutionUnit::DotsPerInch),
        new Resolution(1200, 1200, ResolutionUnit::DotsPerInch),
    ]),
    Attribute::of('printer-resolution-default', ValueTag::Resolution, new Resolution(600, 600, ResolutionUnit::DotsPerInch)),
    new Attribute('pwg-raster-document-resolution-supported', ValueTag::Resolution, [
        new Resolution(300, 300, ResolutionUnit::DotsPerInch),
        new Resolution(600, 600, ResolutionUnit::DotsPerInch),
    ]),
    new Attribute('pwg-raster-document-type-supported', ValueTag::Keyword, ['sgray_8', 'srgb_8']),
    new Attribute('urf-supported', ValueTag::Keyword, ['CP1', 'PQ4-5', 'RS300-600', 'SRGB24', 'W8']),
    new Attribute('print-quality-supported', ValueTag::Enum, [4, 5]),
    new Attribute('marker-names', ValueTag::NameWithoutLanguage, ['Black', 'Cyan', 'Magenta', 'Yellow']),
    new Attribute('marker-levels', ValueTag::Integer, [82, 61, 47, 90]),
    new Attribute('marker-types', ValueTag::Keyword, ['toner', 'toner', 'toner', 'toner']),
    Attribute::of('media-col-ready', ValueTag::BegCollection, $mediaColReady),
]);

$response = new Response(
    StatusCode::SuccessfulOk->value,
    requestId: 1,
    groups: [$operation, $printer],
);

$bytes = (new Encoder)->encodeResponse($response);
file_put_contents(__DIR__.'/get-printer-attributes-response.ipp', $bytes);

fwrite(STDERR, sprintf("wrote %d bytes\n", strlen($bytes)));
