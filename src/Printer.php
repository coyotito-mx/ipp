<?php

declare(strict_types=1);

namespace Coyotito\Ipp;

use Coyotito\Ipp\Capabilities\Capabilities;
use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Exceptions\IppException;
use Coyotito\Ipp\Operations\Operations;
use Coyotito\Ipp\Protocol\Decoder;
use Coyotito\Ipp\Protocol\Encoder;
use Coyotito\Ipp\Protocol\Request;
use Coyotito\Ipp\Protocol\Response;
use Coyotito\Ipp\Transport\GuzzleTransport;
use Coyotito\Ipp\Transport\Transport;

/**
 * The package's high-level entry point: a connection to one printer URI that
 * hides the wire format behind a small, fluent API.
 *
 *     $printer = Printer::connect('ipp://192.168.1.50:631/ipp/print');
 *     $caps    = $printer->capabilities();
 *     $job     = $printer->print($pdf, 'application/pdf')
 *                        ->copies(2)->color()->media('na_legal_8.5x14in')->pages('1-5')
 *                        ->send();
 *     $job->status();
 */
final class Printer
{
    private function __construct(
        private readonly string $uri,
        private readonly Transport $transport,
        private readonly Operations $operations,
        private readonly Encoder $encoder,
        private readonly Decoder $decoder,
    ) {}

    /**
     * Connect to a printer by URI (`ipp://` or `ipps://`). A custom transport
     * (auth, TLS verification) or operations factory (charset, user) can be
     * injected; sensible defaults are used otherwise.
     */
    public static function connect(
        string $uri,
        ?Transport $transport = null,
        ?Operations $operations = null,
    ): self {
        return new self(
            $uri,
            $transport ?? new GuzzleTransport,
            $operations ?? new Operations,
            new Encoder,
            new Decoder,
        );
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function operations(): Operations
    {
        return $this->operations;
    }

    /**
     * The printer's capabilities (Get-Printer-Attributes → typed view).
     *
     * @param  array<int,string>  $requested
     */
    public function capabilities(array $requested = ['all']): Capabilities
    {
        return Capabilities::fromResponse($this->attributes($requested));
    }

    /**
     * Raw Get-Printer-Attributes response.
     *
     * @param  array<int,string>  $requested
     */
    public function attributes(array $requested = []): Response
    {
        return $this->exchangeSuccessful($this->operations->getPrinterAttributes($this->uri, $requested));
    }

    /**
     * Begin a print: returns a fluent builder; nothing is sent until send().
     */
    public function print(string $data, string $format = 'application/octet-stream'): PendingPrint
    {
        return new PendingPrint($this, $data, $format);
    }

    /**
     * A handle to an existing job by id (lazily fetched).
     */
    public function job(int $id): Job
    {
        return new Job($this, $id);
    }

    /**
     * Current jobs on the printer.
     *
     * @param  array<int,string>  $requested
     * @return array<int,Job>
     */
    public function jobs(
        array $requested = ['job-id', 'job-state', 'job-name'],
        string $which = 'not-completed',
    ): array {
        $response = $this->exchangeSuccessful($this->operations->getJobs($this->uri, $requested, $which));

        $jobs = [];

        foreach ($response->groups(DelimiterTag::JobAttributes) as $group) {
            $id = $group->get('job-id')?->firstValue();

            if (is_int($id)) {
                $jobs[] = new Job($this, $id, $group);
            }
        }

        return $jobs;
    }

    /**
     * Encode → transport → decode. Does not throw on IPP error status; callers
     * that require success use {@see exchangeSuccessful()}.
     */
    public function exchange(Request $request): Response
    {
        return $this->decoder->decode($this->transport->send($this->uri, $this->encoder->encode($request)));
    }

    public function exchangeSuccessful(Request $request): Response
    {
        $response = $this->exchange($request);

        if (! $response->isSuccessful()) {
            throw new IppException(sprintf(
                'IPP operation failed with status 0x%04X (%s).',
                $response->statusCode,
                $response->status()?->keyword() ?? 'unknown',
            ));
        }

        return $response;
    }
}
