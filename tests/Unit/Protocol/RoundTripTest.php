<?php

declare(strict_types=1);

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\Operation;
use Coyotito\Ipp\Enums\ResolutionUnit;
use Coyotito\Ipp\Enums\StatusCode;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Protocol\Attribute;
use Coyotito\Ipp\Protocol\AttributeGroup;
use Coyotito\Ipp\Protocol\Decoder;
use Coyotito\Ipp\Protocol\Encoder;
use Coyotito\Ipp\Protocol\Request;
use Coyotito\Ipp\Values\Collection;
use Coyotito\Ipp\Values\DateTimeValue;
use Coyotito\Ipp\Values\IntegerRange;
use Coyotito\Ipp\Values\Resolution;
use Coyotito\Ipp\Values\StringWithLanguage;

function encodeDecode(Request $request): Request
{
    $bytes = (new Encoder)->encode($request);

    return (new Decoder)->decodeRequest($bytes);
}

it('round-trips the message header', function () {
    $request = new Request(Operation::GetPrinterAttributes, requestId: 42);

    $decoded = encodeDecode($request);

    expect($decoded->operation)->toBe(Operation::GetPrinterAttributes)
        ->and($decoded->requestId)->toBe(42)
        ->and($decoded->versionMajor)->toBe(2)
        ->and($decoded->versionMinor)->toBe(0);
});

it('puts charset and natural-language first in operation-attributes', function () {
    $operations = new AttributeGroup(DelimiterTag::OperationAttributes, [
        Attribute::of('attributes-charset', ValueTag::Charset, 'utf-8'),
        Attribute::of('attributes-natural-language', ValueTag::NaturalLanguage, 'en'),
        Attribute::of('printer-uri', ValueTag::Uri, 'ipp://printer.local:631/ipp/print'),
    ]);

    $request = new Request(Operation::GetPrinterAttributes, 1, [$operations]);
    $bytes = (new Encoder)->encode($request);

    // First attribute after the operation-attributes delimiter (0x01) is charset (0x47).
    expect(ord($bytes[8]))->toBe(DelimiterTag::OperationAttributes->value)
        ->and(ord($bytes[9]))->toBe(ValueTag::Charset->value);

    $group = encodeDecode($request)->group(DelimiterTag::OperationAttributes);
    expect($group->all()[0]->name)->toBe('attributes-charset')
        ->and($group->all()[1]->name)->toBe('attributes-natural-language');
});

it('round-trips integer, boolean and enum values', function () {
    $job = new AttributeGroup(DelimiterTag::JobAttributes, [
        Attribute::of('copies', ValueTag::Integer, 3),
        Attribute::of('multiple-document-handling', ValueTag::Boolean, true),
        Attribute::of('finishings', ValueTag::Enum, 4),
        Attribute::of('negative-int', ValueTag::Integer, -12345),
    ]);

    $decoded = encodeDecode(new Request(Operation::CreateJob, 7, [$job]))
        ->group(DelimiterTag::JobAttributes);

    expect($decoded->get('copies')->firstValue())->toBe(3)
        ->and($decoded->get('multiple-document-handling')->firstValue())->toBeTrue()
        ->and($decoded->get('finishings')->firstValue())->toBe(4)
        ->and($decoded->get('negative-int')->firstValue())->toBe(-12345);
});

it('round-trips a 1setOf keyword (multi-valued attribute)', function () {
    $printer = new AttributeGroup(DelimiterTag::OperationAttributes, [
        new Attribute('requested-attributes', ValueTag::Keyword, [
            'printer-state', 'media-ready', 'sides-supported',
        ]),
    ]);

    $attribute = encodeDecode(new Request(Operation::GetPrinterAttributes, 9, [$printer]))
        ->group(DelimiterTag::OperationAttributes)
        ->get('requested-attributes');

    expect($attribute->isMultiValued())->toBeTrue()
        ->and($attribute->values)->toBe(['printer-state', 'media-ready', 'sides-supported']);
});

it('round-trips resolution, rangeOfInteger and dateTime', function () {
    $when = new DateTimeImmutable('2026-06-05T14:30:45.700000-06:00');

    $job = new AttributeGroup(DelimiterTag::JobAttributes, [
        Attribute::of('printer-resolution', ValueTag::Resolution, new Resolution(600, 600, ResolutionUnit::DotsPerInch)),
        Attribute::of('copies-supported', ValueTag::RangeOfInteger, new IntegerRange(1, 99)),
        Attribute::of('date-time-at-creation', ValueTag::DateTime, new DateTimeValue($when)),
    ]);

    $decoded = encodeDecode(new Request(Operation::CreateJob, 11, [$job]))
        ->group(DelimiterTag::JobAttributes);

    $resolution = $decoded->get('printer-resolution')->firstValue();
    expect($resolution)->toBeInstanceOf(Resolution::class)
        ->and($resolution->crossFeed)->toBe(600)
        ->and($resolution->feed)->toBe(600)
        ->and($resolution->units)->toBe(ResolutionUnit::DotsPerInch);

    $range = $decoded->get('copies-supported')->firstValue();
    expect($range)->toBeInstanceOf(IntegerRange::class)
        ->and($range->lower)->toBe(1)
        ->and($range->upper)->toBe(99);

    $date = $decoded->get('date-time-at-creation')->firstValue();
    expect($date)->toBeInstanceOf(DateTimeValue::class)
        ->and($date->value->format('Y-m-d H:i:s'))->toBe('2026-06-05 14:30:45')
        ->and($date->value->getOffset())->toBe(-6 * 3600);
});

it('round-trips textWithLanguage', function () {
    $job = new AttributeGroup(DelimiterTag::JobAttributes, [
        Attribute::of('job-name', ValueTag::NameWithLanguage, new StringWithLanguage('Reporte mensual', 'es-mx')),
    ]);

    $value = encodeDecode(new Request(Operation::CreateJob, 13, [$job]))
        ->group(DelimiterTag::JobAttributes)
        ->get('job-name')
        ->firstValue();

    expect($value)->toBeInstanceOf(StringWithLanguage::class)
        ->and($value->value)->toBe('Reporte mensual')
        ->and($value->language)->toBe('es-mx');
});

it('round-trips a nested collection (media-col)', function () {
    $mediaSize = new Collection([
        Attribute::of('x-dimension', ValueTag::Integer, 21000),
        Attribute::of('y-dimension', ValueTag::Integer, 29700),
    ]);

    $mediaCol = new Collection([
        Attribute::of('media-size', ValueTag::BegCollection, $mediaSize),
        Attribute::of('media-type', ValueTag::Keyword, 'stationery'),
        new Attribute('media-source', ValueTag::Keyword, ['tray-1', 'tray-2']),
    ]);

    $job = new AttributeGroup(DelimiterTag::JobAttributes, [
        Attribute::of('media-col', ValueTag::BegCollection, $mediaCol),
    ]);

    $decoded = encodeDecode(new Request(Operation::CreateJob, 17, [$job]))
        ->group(DelimiterTag::JobAttributes)
        ->get('media-col')
        ->firstValue();

    expect($decoded)->toBeInstanceOf(Collection::class)
        ->and($decoded->get('media-type')->firstValue())->toBe('stationery')
        ->and($decoded->get('media-source')->values)->toBe(['tray-1', 'tray-2']);

    $size = $decoded->get('media-size')->firstValue();
    expect($size)->toBeInstanceOf(Collection::class)
        ->and($size->get('x-dimension')->firstValue())->toBe(21000)
        ->and($size->get('y-dimension')->firstValue())->toBe(29700);
});

it('round-trips out-of-band values', function () {
    $job = new AttributeGroup(DelimiterTag::JobAttributes, [
        new Attribute('job-hold-until', ValueTag::NoValue, []),
    ]);

    $attribute = encodeDecode(new Request(Operation::CreateJob, 19, [$job]))
        ->group(DelimiterTag::JobAttributes)
        ->get('job-hold-until');

    expect($attribute->tag)->toBe(ValueTag::NoValue)
        ->and($attribute->values)->toBe([]);
});

it('preserves the document data trailer', function () {
    $request = new Request(
        Operation::PrintJob,
        23,
        [new AttributeGroup(DelimiterTag::OperationAttributes, [
            Attribute::of('attributes-charset', ValueTag::Charset, 'utf-8'),
        ])],
        data: '%PDF-1.7 binary payload',
    );

    expect(encodeDecode($request)->data)->toBe('%PDF-1.7 binary payload');
});

it('decodes a status code from a response', function () {
    // Minimal response: version 2.0, status successful-ok, request-id 5, no attrs.
    $bytes = chr(2).chr(0).pack('n', StatusCode::SuccessfulOk->value).pack('N', 5)
        .chr(DelimiterTag::EndOfAttributes->value);

    $response = (new Decoder)->decode($bytes);

    expect($response->statusCode)->toBe(0x0000)
        ->and($response->status())->toBe(StatusCode::SuccessfulOk)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->requestId)->toBe(5);
});
