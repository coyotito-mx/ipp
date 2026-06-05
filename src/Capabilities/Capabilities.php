<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Capabilities;

use Coyotito\Ipp\Enums\PrinterState;
use Coyotito\Ipp\Exceptions\IppException;
use Coyotito\Ipp\Protocol\Attribute;
use Coyotito\Ipp\Protocol\AttributeGroup;
use Coyotito\Ipp\Protocol\Response;
use Coyotito\Ipp\Values\Collection;
use Coyotito\Ipp\Values\IntegerRange;
use Coyotito\Ipp\Values\Resolution;

/**
 * A typed, vendor-agnostic view over a printer's attributes (the response to
 * Get-Printer-Attributes, RFC 8011 §5.4). Wraps the raw attribute group and
 * exposes the questions a print queue actually asks: is it online, what paper
 * is loaded, can it do color/duplex, which formats it speaks, and ink levels.
 */
final class Capabilities
{
    public function __construct(
        private readonly AttributeGroup $attributes,
    ) {}

    public static function fromResponse(Response $response): self
    {
        $printer = $response->printerAttributes();

        if (! $printer instanceof AttributeGroup) {
            throw new IppException('Response has no printer-attributes group.');
        }

        return new self($printer);
    }

    public function attributes(): AttributeGroup
    {
        return $this->attributes;
    }

    public function get(string $name): ?Attribute
    {
        return $this->attributes->get($name);
    }

    /**
     * @return array<int,mixed>
     */
    public function values(string $name): array
    {
        return $this->attributes->get($name)?->values ?? [];
    }

    public function first(string $name): mixed
    {
        return $this->attributes->get($name)?->firstValue();
    }

    // ---- Identity -----------------------------------------------------------

    public function name(): ?string
    {
        $value = $this->first('printer-name');

        return is_scalar($value) ? (string) $value : null;
    }

    public function makeAndModel(): ?string
    {
        $value = $this->first('printer-make-and-model');

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return array<int,string>
     */
    public function ippVersionsSupported(): array
    {
        return $this->strings('ipp-versions-supported');
    }

    // ---- State --------------------------------------------------------------

    public function state(): ?PrinterState
    {
        $value = $this->first('printer-state');

        return is_int($value) ? PrinterState::tryFrom($value) : null;
    }

    /**
     * @return array<int,string>
     */
    public function stateReasons(): array
    {
        return $this->strings('printer-state-reasons');
    }

    public function isAcceptingJobs(): bool
    {
        return $this->first('printer-is-accepting-jobs') === true;
    }

    public function isIdle(): bool
    {
        return $this->state() === PrinterState::Idle;
    }

    public function isStopped(): bool
    {
        return $this->state() === PrinterState::Stopped;
    }

    /**
     * Reachable and not stopped: idle or actively processing. (Reachability is
     * implied — these attributes came back over the wire.)
     */
    public function isOnline(): bool
    {
        return in_array($this->state(), [PrinterState::Idle, PrinterState::Processing], true);
    }

    // ---- Formats ------------------------------------------------------------

    /**
     * @return array<int,string>
     */
    public function documentFormatsSupported(): array
    {
        return $this->strings('document-format-supported');
    }

    public function supportsFormat(string $mimeType): bool
    {
        return in_array($mimeType, $this->documentFormatsSupported(), true);
    }

    /**
     * Does the printer rasterize PDF itself? If true, the local app can send
     * the PDF as-is; if false, it must rasterize before printing.
     */
    public function supportsPdf(): bool
    {
        return $this->supportsFormat('application/pdf');
    }

    /** PWG Raster (IPP Everywhere standard raster). */
    public function supportsPwgRaster(): bool
    {
        return $this->supportsFormat('image/pwg-raster');
    }

    /** Apple Raster / URF (AirPrint raster). */
    public function supportsAppleRaster(): bool
    {
        return $this->supportsFormat('image/urf');
    }

    /**
     * Pick the first format from a preference list that the printer accepts —
     * the basis for deciding whether (and to what) the local app rasterizes.
     * Returns null if none of the preferred formats are supported.
     *
     * @param  array<int,string>  $preference
     */
    public function preferredFormat(array $preference): ?string
    {
        foreach ($preference as $format) {
            if ($this->supportsFormat($format)) {
                return $format;
            }
        }

        return null;
    }

    // ---- Rendering / rasterization ------------------------------------------

    /**
     * General device resolutions (`printer-resolution-supported`).
     *
     * @return array<int,Resolution>
     */
    public function resolutionsSupported(): array
    {
        return $this->resolutions('printer-resolution-supported');
    }

    public function defaultResolution(): ?Resolution
    {
        $value = $this->first('printer-resolution-default');

        return $value instanceof Resolution ? $value : null;
    }

    /**
     * Resolutions valid for PWG Raster output
     * (`pwg-raster-document-resolution-supported`) — what to rasterize at.
     *
     * @return array<int,Resolution>
     */
    public function pwgRasterResolutions(): array
    {
        return $this->resolutions('pwg-raster-document-resolution-supported');
    }

    /**
     * PWG Raster colorspace/bit-depth keywords (`pwg-raster-document-type-supported`),
     * e.g. `srgb_8`, `sgray_8`.
     *
     * @return array<int,string>
     */
    public function pwgRasterTypes(): array
    {
        return $this->strings('pwg-raster-document-type-supported');
    }

    /**
     * Apple Raster (URF) capability keywords (`urf-supported`); encode the
     * supported resolutions, color spaces and duplex for AirPrint raster.
     *
     * @return array<int,string>
     */
    public function urfSupported(): array
    {
        return $this->strings('urf-supported');
    }

    /**
     * Print-quality enums supported: 3 = draft, 4 = normal, 5 = high.
     *
     * @return array<int,int>
     */
    public function printQualitiesSupported(): array
    {
        return $this->ints('print-quality-supported');
    }

    /**
     * Highest device resolution offered, by total dpi (handy when rasterizing
     * "at the best quality the printer allows").
     */
    public function maxResolution(): ?Resolution
    {
        $best = null;

        foreach ([...$this->resolutionsSupported(), ...$this->pwgRasterResolutions()] as $resolution) {
            if ($best === null || ($resolution->crossFeed * $resolution->feed) > ($best->crossFeed * $best->feed)) {
                $best = $resolution;
            }
        }

        return $best;
    }

    // ---- Media (paper) ------------------------------------------------------

    /**
     * Media names the printer can take (PWG self-describing names).
     *
     * @return array<int,string>
     */
    public function mediaSupported(): array
    {
        return $this->strings('media-supported');
    }

    /**
     * Media currently loaded and ready (the key check for routing a job).
     *
     * @return array<int,string>
     */
    public function mediaReady(): array
    {
        return $this->strings('media-ready');
    }

    /**
     * Whether a given media name is loaded right now.
     */
    public function hasMediaReady(string $media): bool
    {
        return in_array($media, $this->mediaReady(), true);
    }

    /**
     * Structured loaded media (`media-col-ready`): each collection carries
     * size, type, source and margins.
     *
     * @return array<int,Collection>
     */
    public function mediaColReady(): array
    {
        return array_values(array_filter(
            $this->values('media-col-ready'),
            static fn (mixed $v): bool => $v instanceof Collection,
        ));
    }

    // ---- Sides / duplex -----------------------------------------------------

    /**
     * @return array<int,string>
     */
    public function sidesSupported(): array
    {
        return $this->strings('sides-supported');
    }

    public function supportsDuplex(): bool
    {
        foreach ($this->sidesSupported() as $side) {
            if (str_starts_with($side, 'two-sided')) {
                return true;
            }
        }

        return false;
    }

    // ---- Color --------------------------------------------------------------

    /**
     * @return array<int,string>
     */
    public function colorModesSupported(): array
    {
        return $this->strings('print-color-mode-supported');
    }

    /**
     * `color-supported` (boolean) is authoritative when present; otherwise fall
     * back to a 'color' entry in print-color-mode-supported.
     */
    public function supportsColor(): bool
    {
        $flag = $this->first('color-supported');

        if (is_bool($flag)) {
            return $flag;
        }

        return in_array('color', $this->colorModesSupported(), true);
    }

    // ---- Copies / page ranges ----------------------------------------------

    public function copiesSupported(): ?IntegerRange
    {
        $value = $this->first('copies-supported');

        return $value instanceof IntegerRange ? $value : null;
    }

    public function maxCopies(): ?int
    {
        return $this->copiesSupported()?->upper;
    }

    public function supportsPageRanges(): bool
    {
        return $this->first('page-ranges-supported') === true;
    }

    // ---- Supplies (ink / toner) --------------------------------------------

    /**
     * Ink/toner levels assembled from the index-aligned `marker-*` attributes.
     *
     * @return array<int,MarkerLevel>
     */
    public function inkLevels(): array
    {
        $levels = $this->ints('marker-levels');

        if ($levels === []) {
            return [];
        }

        $names = $this->strings('marker-names');
        $types = $this->strings('marker-types');
        $colors = $this->strings('marker-colors');
        $low = $this->ints('marker-low-levels');
        $high = $this->ints('marker-high-levels');

        $markers = [];

        foreach ($levels as $i => $level) {
            $markers[] = new MarkerLevel(
                name: $names[$i] ?? "marker-{$i}",
                level: $level,
                type: $types[$i] ?? null,
                color: $colors[$i] ?? null,
                lowLevel: $low[$i] ?? 0,
                highLevel: $high[$i] ?? 100,
            );
        }

        return $markers;
    }

    /**
     * @return array<int,string>
     */
    private function strings(string $name): array
    {
        return array_values(array_map(
            static fn (mixed $v): string => (string) $v,
            array_filter($this->values($name), static fn (mixed $v): bool => is_scalar($v) || $v instanceof \Stringable),
        ));
    }

    /**
     * @return array<int,int>
     */
    private function ints(string $name): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => (int) $v,
            array_filter($this->values($name), 'is_int'),
        ));
    }

    /**
     * @return array<int,Resolution>
     */
    private function resolutions(string $name): array
    {
        return array_values(array_filter(
            $this->values($name),
            static fn (mixed $v): bool => $v instanceof Resolution,
        ));
    }
}
