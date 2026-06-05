<?php

declare(strict_types=1);

use Coyotito\Ipp\Enums\StatusCode;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Protocol\Decoder;
use Coyotito\Ipp\Protocol\Response;
use Coyotito\Ipp\Values\Collection;
use Coyotito\Ipp\Values\IntegerRange;

function gpaFixture(): Response
{
    $bytes = file_get_contents(__DIR__.'/../../Fixtures/get-printer-attributes-response.ipp');

    return (new Decoder)->decode($bytes);
}

it('decodes a Get-Printer-Attributes response fixture', function () {
    $response = gpaFixture();

    expect($response->status())->toBe(StatusCode::SuccessfulOk)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->requestId)->toBe(1)
        ->and($response->printerAttributes())->not->toBeNull();
});

it('reads scalar printer attributes', function () {
    $printer = gpaFixture()->printerAttributes();

    expect($printer->get('printer-name')->firstValue())->toBe('ipp/print')
        ->and($printer->get('printer-make-and-model')->firstValue())->toBe('Acme LaserJet 9000')
        ->and($printer->get('printer-state')->firstValue())->toBe(3)
        ->and($printer->get('color-supported')->firstValue())->toBeTrue();

    $copies = $printer->get('copies-supported')->firstValue();
    expect($copies)->toBeInstanceOf(IntegerRange::class)
        ->and($copies->lower)->toBe(1)
        ->and($copies->upper)->toBe(99);
});

it('reads multi-valued keyword attributes', function () {
    $printer = gpaFixture()->printerAttributes();

    expect($printer->get('media-ready')->values)
        ->toBe(['na_letter_8.5x11in', 'na_legal_8.5x14in'])
        ->and($printer->get('document-format-supported')->values)
        ->toContain('application/pdf')
        ->and($printer->get('sides-supported')->values)
        ->toContain('two-sided-long-edge')
        ->and($printer->get('ipp-versions-supported')->values)
        ->toBe(['1.0', '1.1', '2.0']);
});

it('reads a nested media-col-ready collection from the fixture', function () {
    $mediaCol = gpaFixture()->printerAttributes()->get('media-col-ready')->firstValue();

    expect($mediaCol)->toBeInstanceOf(Collection::class)
        ->and($mediaCol->get('media-type')->firstValue())->toBe('stationery');

    $size = $mediaCol->get('media-size')->firstValue();
    expect($size)->toBeInstanceOf(Collection::class)
        ->and($size->get('x-dimension')->firstValue())->toBe(21590)
        ->and($size->get('y-dimension')->firstValue())->toBe(27940);
});

it('keeps marker (ink) levels aligned with their names', function () {
    $printer = gpaFixture()->printerAttributes();

    expect($printer->get('marker-names')->values)->toBe(['Black', 'Cyan', 'Magenta', 'Yellow'])
        ->and($printer->get('marker-levels')->values)->toBe([82, 61, 47, 90])
        ->and($printer->get('marker-levels')->tag)->toBe(ValueTag::Integer);
});
