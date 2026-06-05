<?php

declare(strict_types=1);

namespace Coyotito\Ipp;

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Exceptions\IppException;
use Coyotito\Ipp\Operations\PrintOptions;
use Coyotito\Ipp\Protocol\Response;

/**
 * A print being assembled. Returned by {@see Printer::print()}; the document is
 * only submitted on {@see send()} (or checked with {@see validate()}). Print
 * option helpers delegate to a {@see PrintOptions} builder for a flat, fluent
 * chain.
 */
final class PendingPrint
{
    private PrintOptions $options;

    private ?string $jobName = null;

    public function __construct(
        private readonly Printer $printer,
        private readonly string $data,
        private string $format,
    ) {
        $this->options = PrintOptions::make();
    }

    public function copies(int $count): self
    {
        $this->options->copies($count);

        return $this;
    }

    public function color(): self
    {
        $this->options->color();

        return $this;
    }

    public function monochrome(): self
    {
        $this->options->monochrome();

        return $this;
    }

    public function sides(string $sides): self
    {
        $this->options->sides($sides);

        return $this;
    }

    public function simplex(): self
    {
        $this->options->simplex();

        return $this;
    }

    public function duplex(bool $longEdge = true): self
    {
        $this->options->duplex($longEdge);

        return $this;
    }

    public function media(string $media): self
    {
        $this->options->media($media);

        return $this;
    }

    public function pages(string $ranges): self
    {
        $this->options->pages($ranges);

        return $this;
    }

    public function quality(int $quality): self
    {
        $this->options->quality($quality);

        return $this;
    }

    /**
     * Replace the whole option set (e.g. one prepared elsewhere).
     */
    public function withOptions(PrintOptions $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function name(string $jobName): self
    {
        $this->jobName = $jobName;

        return $this;
    }

    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Validate-Job: ask whether the printer would accept this, without printing.
     */
    public function validate(): Response
    {
        return $this->printer->exchange(
            $this->printer->operations()->validateJob($this->printer->uri(), $this->format, $this->options->toGroup())
        );
    }

    /**
     * Submit the document (Print-Job) and return the created {@see Job}.
     */
    public function send(): Job
    {
        $response = $this->printer->exchangeSuccessful(
            $this->printer->operations()->printJob(
                $this->printer->uri(),
                $this->data,
                $this->format,
                $this->jobName,
                $this->options->toGroup(),
            )
        );

        $group = $response->group(DelimiterTag::JobAttributes);
        $jobId = $group?->get('job-id')?->firstValue();

        if (! is_int($jobId)) {
            throw new IppException('Print-Job succeeded but the response carried no job-id.');
        }

        return new Job($this->printer, $jobId, $group);
    }
}
