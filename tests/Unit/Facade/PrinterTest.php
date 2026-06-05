<?php

declare(strict_types=1);

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\JobState;
use Coyotito\Ipp\Enums\Operation;
use Coyotito\Ipp\Enums\StatusCode;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Exceptions\IppException;
use Coyotito\Ipp\Printer;
use Coyotito\Ipp\Protocol\Attribute;
use Coyotito\Ipp\Protocol\AttributeGroup;
use Coyotito\Ipp\Protocol\Decoder;
use Coyotito\Ipp\Protocol\Encoder;
use Coyotito\Ipp\Protocol\Request;
use Coyotito\Ipp\Protocol\Response;
use Coyotito\Ipp\Transport\Transport;

/**
 * Records each request it is asked to send and replies with queued, encoded
 * responses — exercises the full facade stack without a real printer.
 */
final class FakeTransport implements Transport
{
    /** @var array<int,string> queued raw response bytes */
    public array $responses = [];

    /** @var array<int,Request> decoded requests that were sent */
    public array $sent = [];

    public function queue(Response $response): void
    {
        $this->responses[] = (new Encoder)->encodeResponse($response);
    }

    public function send(string $uri, string $message): string
    {
        $this->sent[] = (new Decoder)->decodeRequest($message);

        return array_shift($this->responses) ?? throw new RuntimeException('No queued response.');
    }
}

function jobResponse(int $jobId, JobState $state, int $requestId = 1): Response
{
    return new Response(StatusCode::SuccessfulOk->value, $requestId, [
        new AttributeGroup(DelimiterTag::JobAttributes, [
            Attribute::of('job-id', ValueTag::Integer, $jobId),
            Attribute::of('job-uri', ValueTag::Uri, "ipp://printer.local:631/jobs/{$jobId}"),
            Attribute::of('job-state', ValueTag::Enum, $state->value),
            new Attribute('job-state-reasons', ValueTag::Keyword, ['none']),
        ]),
    ]);
}

it('reads capabilities through the facade', function () {
    $transport = new FakeTransport;
    $transport->responses[] = file_get_contents(__DIR__.'/../../Fixtures/get-printer-attributes-response.ipp');

    $caps = Printer::connect('ipp://printer.local:631/ipp/print', $transport)->capabilities();

    expect($caps->makeAndModel())->toBe('Acme LaserJet 9000')
        ->and($caps->hasMediaReady('na_letter_8.5x11in'))->toBeTrue();

    // The request it sent was a Get-Printer-Attributes asking for 'all'.
    $sent = $transport->sent[0];
    expect($sent->operation)->toBe(Operation::GetPrinterAttributes)
        ->and($sent->group(DelimiterTag::OperationAttributes)->get('requested-attributes')->values)->toBe(['all']);
});

it('submits a print through the fluent builder and returns a job', function () {
    $transport = new FakeTransport;
    $transport->queue(jobResponse(13, JobState::Pending));

    $job = Printer::connect('ipp://printer.local:631/ipp/print', $transport)
        ->print('%PDF...', 'application/pdf')
        ->copies(2)
        ->monochrome()
        ->media('na_letter_8.5x11in')
        ->pages('1-3')
        ->name('Reporte')
        ->send();

    expect($job->id)->toBe(13);

    // Inspect what actually went on the wire.
    $sent = $transport->sent[0];
    expect($sent->operation)->toBe(Operation::PrintJob)
        ->and($sent->data)->toBe('%PDF...');

    $op = $sent->group(DelimiterTag::OperationAttributes);
    expect($op->get('document-format')->firstValue())->toBe('application/pdf')
        ->and($op->get('job-name')->firstValue())->toBe('Reporte');

    $jobAttrs = $sent->group(DelimiterTag::JobAttributes);
    expect($jobAttrs->get('copies')->firstValue())->toBe(2)
        ->and($jobAttrs->get('print-color-mode')->firstValue())->toBe('monochrome')
        ->and($jobAttrs->get('media')->firstValue())->toBe('na_letter_8.5x11in');
});

it('polls job status and reports terminal state', function () {
    $transport = new FakeTransport;
    $transport->queue(jobResponse(20, JobState::Pending));        // Print-Job
    $transport->queue(jobResponse(20, JobState::Processing));     // status() #1
    $transport->queue(jobResponse(20, JobState::Completed));      // status() #2

    $job = Printer::connect('ipp://printer.local:631/ipp/print', $transport)
        ->print('data')->send();

    expect($job->status())->toBe(JobState::Processing)
        ->and($job->isCompleted())->toBeFalse();

    expect($job->status())->toBe(JobState::Completed)
        ->and($job->isCompleted())->toBeTrue()
        ->and($job->isTerminal())->toBeTrue();

    // Print-Job + two Get-Job-Attributes.
    expect($transport->sent)->toHaveCount(3)
        ->and($transport->sent[1]->operation)->toBe(Operation::GetJobAttributes);
});

it('cancels a job', function () {
    $transport = new FakeTransport;
    $transport->queue(jobResponse(7, JobState::Pending));                     // Print-Job
    $transport->queue(new Response(StatusCode::SuccessfulOk->value, 1, []));  // Cancel-Job

    $job = Printer::connect('ipp://printer.local:631/ipp/print', $transport)
        ->print('data')->send();

    expect($job->cancel())->toBeTrue()
        ->and($transport->sent[1]->operation)->toBe(Operation::CancelJob)
        ->and($transport->sent[1]->group(DelimiterTag::OperationAttributes)->get('job-id')->firstValue())->toBe(7);
});

it('throws when an operation returns an error status', function () {
    $transport = new FakeTransport;
    $transport->queue(new Response(StatusCode::ClientErrorNotFound->value, 1, []));

    expect(fn () => Printer::connect('ipp://printer.local:631/ipp/print', $transport)->attributes())
        ->toThrow(IppException::class);
});
