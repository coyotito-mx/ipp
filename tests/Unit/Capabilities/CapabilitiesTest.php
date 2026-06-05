<?php

declare(strict_types=1);

use Coyotito\Ipp\Capabilities\Capabilities;
use Coyotito\Ipp\Enums\PrinterState;
use Coyotito\Ipp\Protocol\Decoder;

function caps(): Capabilities
{
    $bytes = file_get_contents(__DIR__.'/../../Fixtures/get-printer-attributes-response.ipp');

    return Capabilities::fromResponse((new Decoder)->decode($bytes));
}

it('reads identity attributes', function () {
    expect(caps()->name())->toBe('ipp/print')
        ->and(caps()->makeAndModel())->toBe('Acme LaserJet 9000')
        ->and(caps()->ippVersionsSupported())->toBe(['1.0', '1.1', '2.0']);
});

it('reports printer state', function () {
    $caps = caps();

    expect($caps->state())->toBe(PrinterState::Idle)
        ->and($caps->isIdle())->toBeTrue()
        ->and($caps->isStopped())->toBeFalse()
        ->and($caps->isOnline())->toBeTrue()
        ->and($caps->stateReasons())->toBe(['none']);
});

it('answers document-format questions', function () {
    $caps = caps();

    expect($caps->supportsFormat('application/pdf'))->toBeTrue()
        ->and($caps->supportsFormat('image/jpeg'))->toBeTrue()
        ->and($caps->supportsFormat('text/plain'))->toBeFalse();
});

it('distinguishes loaded media from merely supported media', function () {
    $caps = caps();

    expect($caps->mediaReady())->toBe(['na_letter_8.5x11in', 'na_legal_8.5x14in'])
        ->and($caps->hasMediaReady('na_letter_8.5x11in'))->toBeTrue()
        ->and($caps->hasMediaReady('na_legal_8.5x14in'))->toBeTrue()
        // a4 is supported but not currently loaded
        ->and($caps->mediaSupported())->toContain('iso_a4_210x297mm')
        ->and($caps->hasMediaReady('iso_a4_210x297mm'))->toBeFalse();
});

it('reads the media-col-ready collection', function () {
    $ready = caps()->mediaColReady();

    expect($ready)->toHaveCount(1)
        ->and($ready[0]->get('media-type')->firstValue())->toBe('stationery');
});

it('answers color and duplex capability', function () {
    $caps = caps();

    expect($caps->supportsColor())->toBeTrue()
        ->and($caps->supportsDuplex())->toBeTrue()
        ->and($caps->colorModesSupported())->toContain('monochrome');
});

it('reads the copies range', function () {
    $caps = caps();

    expect($caps->maxCopies())->toBe(99)
        ->and($caps->copiesSupported()->contains(50))->toBeTrue()
        ->and($caps->copiesSupported()->contains(100))->toBeFalse();
});

it('assembles ink/marker levels from index-aligned attributes', function () {
    $ink = caps()->inkLevels();

    expect($ink)->toHaveCount(4)
        ->and($ink[0]->name)->toBe('Black')
        ->and($ink[0]->level)->toBe(82)
        ->and($ink[0]->type)->toBe('toner')
        ->and($ink[0]->percentage())->toBe(82)
        ->and($ink[0]->isKnown())->toBeTrue()
        ->and($ink[2]->name)->toBe('Magenta')
        ->and($ink[2]->level)->toBe(47);
});

it('treats absent boolean attributes as false', function () {
    // The fixture omits printer-is-accepting-jobs and page-ranges-supported.
    expect(caps()->isAcceptingJobs())->toBeFalse()
        ->and(caps()->supportsPageRanges())->toBeFalse();
});

it('exposes rasterization formats and resolutions', function () {
    $caps = caps();

    expect($caps->supportsPdf())->toBeTrue()
        ->and($caps->supportsPwgRaster())->toBeTrue()
        ->and($caps->supportsAppleRaster())->toBeTrue()
        ->and($caps->pwgRasterResolutions())->toHaveCount(2)
        ->and((string) $caps->pwgRasterResolutions()[1])->toBe('600x600dpi')
        ->and($caps->pwgRasterTypes())->toBe(['sgray_8', 'srgb_8'])
        ->and($caps->urfSupported())->toContain('RS300-600')
        ->and($caps->printQualitiesSupported())->toBe([4, 5]);

    expect((string) $caps->defaultResolution())->toBe('600x600dpi')
        ->and((string) $caps->maxResolution())->toBe('1200x1200dpi');
});

it('picks the first supported format from a preference list', function () {
    $caps = caps();

    // First entry in the preference order that the printer supports wins.
    expect($caps->preferredFormat(['application/pdf', 'image/pwg-raster', 'image/urf']))
        ->toBe('application/pdf')
        ->and($caps->preferredFormat(['image/pwg-raster', 'application/pdf']))
        ->toBe('image/pwg-raster')
        ->and($caps->preferredFormat(['image/png', 'image/tiff']))->toBeNull();
});
