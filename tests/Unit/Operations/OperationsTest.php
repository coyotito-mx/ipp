<?php

declare(strict_types=1);

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\Operation;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Exceptions\IppException;
use Coyotito\Ipp\Operations\Operations;
use Coyotito\Ipp\Operations\PrintOptions;
use Coyotito\Ipp\Protocol\Decoder;
use Coyotito\Ipp\Protocol\Encoder;
use Coyotito\Ipp\Protocol\Request;
use Coyotito\Ipp\Values\IntegerRange;

const PRINTER = 'ipp://printer.local:631/ipp/print';

function rebuild(Request $request): Request
{
    return (new Decoder)->decodeRequest((new Encoder)->encode($request));
}

it('issues sequential request-ids per instance', function () {
    $ops = new Operations;

    expect($ops->getPrinterAttributes(PRINTER)->requestId)->toBe(1)
        ->and($ops->getPrinterAttributes(PRINTER)->requestId)->toBe(2)
        ->and($ops->cancelJob(PRINTER, 5)->requestId)->toBe(3);
});

it('builds a conformant Get-Printer-Attributes request', function () {
    $request = (new Operations)->getPrinterAttributes(PRINTER, ['all', 'media-col-database']);

    expect($request->operation)->toBe(Operation::GetPrinterAttributes);

    $operation = $request->group(DelimiterTag::OperationAttributes);
    $attrs = $operation->all();

    // charset and natural-language must be the first two attributes (§4.1.4).
    expect($attrs[0]->name)->toBe('attributes-charset')
        ->and($attrs[0]->tag)->toBe(ValueTag::Charset)
        ->and($attrs[0]->firstValue())->toBe('utf-8')
        ->and($attrs[1]->name)->toBe('attributes-natural-language')
        ->and($attrs[1]->firstValue())->toBe('en')
        ->and($operation->get('printer-uri')->firstValue())->toBe(PRINTER)
        ->and($operation->get('requested-attributes')->values)->toBe(['all', 'media-col-database']);
});

it('omits requested-attributes when none are given', function () {
    $request = (new Operations)->getPrinterAttributes(PRINTER);

    expect($request->group(DelimiterTag::OperationAttributes)->has('requested-attributes'))->toBeFalse();
});

it('builds a Print-Job request with print options and data', function () {
    $options = PrintOptions::make()
        ->copies(2)
        ->color()
        ->duplex()
        ->media('na_legal_8.5x14in')
        ->pages('1-5,8');

    $request = (new Operations)->printJob(
        PRINTER,
        data: '%PDF-1.7 ...',
        documentFormat: 'application/pdf',
        jobName: 'Reporte',
        jobAttributes: $options->toGroup(),
    );

    $request = rebuild($request);

    expect($request->operation)->toBe(Operation::PrintJob)
        ->and($request->data)->toBe('%PDF-1.7 ...');

    $operation = $request->group(DelimiterTag::OperationAttributes);
    expect($operation->get('document-format')->firstValue())->toBe('application/pdf')
        ->and($operation->get('document-format')->tag)->toBe(ValueTag::MimeMediaType)
        ->and($operation->get('job-name')->firstValue())->toBe('Reporte');

    $job = $request->group(DelimiterTag::JobAttributes);
    expect($job->get('copies')->firstValue())->toBe(2)
        ->and($job->get('print-color-mode')->firstValue())->toBe('color')
        ->and($job->get('sides')->firstValue())->toBe('two-sided-long-edge')
        ->and($job->get('media')->firstValue())->toBe('na_legal_8.5x14in');

    $ranges = $job->get('page-ranges');
    expect($ranges->tag)->toBe(ValueTag::RangeOfInteger)
        ->and($ranges->values)->toEqual([new IntegerRange(1, 5), new IntegerRange(8, 8)]);
});

it('builds a Validate-Job request without document data', function () {
    $request = (new Operations)->validateJob(PRINTER, 'application/pdf');

    expect($request->operation)->toBe(Operation::ValidateJob)
        ->and($request->data)->toBe('')
        ->and($request->group(DelimiterTag::OperationAttributes)->get('document-format')->firstValue())
        ->toBe('application/pdf');
});

it('builds Create-Job and Send-Document for a multi-document job', function () {
    $ops = new Operations;

    $create = $ops->createJob(PRINTER, 'Multi');
    expect($create->operation)->toBe(Operation::CreateJob);

    $send = $ops->sendDocument(PRINTER, jobId: 42, data: 'doc bytes', documentFormat: 'application/pdf', lastDocument: true);
    $send = rebuild($send);

    $operation = $send->group(DelimiterTag::OperationAttributes);
    expect($send->operation)->toBe(Operation::SendDocument)
        ->and($send->data)->toBe('doc bytes')
        ->and($operation->get('job-id')->firstValue())->toBe(42)
        ->and($operation->get('last-document')->firstValue())->toBeTrue();
});

it('builds Get-Jobs with which-jobs and limit', function () {
    $request = rebuild((new Operations)->getJobs(PRINTER, ['job-id', 'job-state'], which: 'completed', limit: 10, myJobs: true));

    $operation = $request->group(DelimiterTag::OperationAttributes);
    expect($request->operation)->toBe(Operation::GetJobs)
        ->and($operation->get('which-jobs')->firstValue())->toBe('completed')
        ->and($operation->get('my-jobs')->firstValue())->toBeTrue()
        ->and($operation->get('limit')->firstValue())->toBe(10)
        ->and($operation->get('requested-attributes')->values)->toBe(['job-id', 'job-state']);
});

it('builds a Cancel-Job request targeting a job-id', function () {
    $request = (new Operations)->cancelJob(PRINTER, 7);

    expect($request->operation)->toBe(Operation::CancelJob)
        ->and($request->group(DelimiterTag::OperationAttributes)->get('job-id')->firstValue())->toBe(7);
});

it('rejects invalid copies and unparsable page ranges', function () {
    expect(fn () => PrintOptions::make()->copies(0))->toThrow(IppException::class);
    expect(fn () => PrintOptions::make()->pages(' , '))->toThrow(IppException::class);
});
