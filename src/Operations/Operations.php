<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Operations;

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\Operation;
use Coyotito\Ipp\Enums\ValueTag;
use Coyotito\Ipp\Protocol\Attribute;
use Coyotito\Ipp\Protocol\AttributeGroup;
use Coyotito\Ipp\Protocol\Request;

/**
 * Builds {@see Request} messages for the IPP operations the package supports
 * (RFC 8011 §4). Every request is assembled so that the operation-attributes
 * group comes first and `attributes-charset` / `attributes-natural-language`
 * are its first two attributes, as the standard mandates (§4.1.4).
 *
 * Request-ids are issued sequentially per instance (one per connection),
 * starting at 1.
 */
final class Operations
{
    public const string DEFAULT_CHARSET = 'utf-8';

    public const string DEFAULT_LANGUAGE = 'en';

    /** RFC 8011 §4.1.9.1 anonymous user fallback. */
    public const string DEFAULT_USER = 'anonymous';

    private int $nextRequestId = 1;

    public function __construct(
        private readonly string $charset = self::DEFAULT_CHARSET,
        private readonly string $naturalLanguage = self::DEFAULT_LANGUAGE,
        private readonly string $requestingUser = self::DEFAULT_USER,
    ) {}

    /**
     * Get-Printer-Attributes (§4.2.5). An empty $requested list lets the
     * printer return its default attribute set; pass ['all'] for everything.
     *
     * @param  array<int,string>  $requested
     */
    public function getPrinterAttributes(string $printerUri, array $requested = [], ?int $requestId = null): Request
    {
        $operation = $this->operationGroup($printerUri);

        if ($requested !== []) {
            $operation->add(new Attribute('requested-attributes', ValueTag::Keyword, $requested));
        }

        return $this->request(Operation::GetPrinterAttributes, $operation, $requestId);
    }

    /**
     * Validate-Job (§4.2.3): check that a job would be accepted, without
     * printing. Carries the same operation/job attributes as Print-Job but no
     * document data.
     */
    public function validateJob(
        string $printerUri,
        string $documentFormat = 'application/octet-stream',
        ?AttributeGroup $jobAttributes = null,
        ?int $requestId = null,
    ): Request {
        $operation = $this->operationGroup($printerUri);
        $operation->add(Attribute::of('document-format', ValueTag::MimeMediaType, $documentFormat));

        return $this->request(Operation::ValidateJob, $operation, $requestId, $jobAttributes);
    }

    /**
     * Print-Job (§4.2.1): submit a single document and its data in one request.
     */
    public function printJob(
        string $printerUri,
        string $data,
        string $documentFormat = 'application/octet-stream',
        ?string $jobName = null,
        ?AttributeGroup $jobAttributes = null,
        ?int $requestId = null,
    ): Request {
        $operation = $this->operationGroup($printerUri);
        $operation->add(Attribute::of('document-format', ValueTag::MimeMediaType, $documentFormat));

        if ($jobName !== null) {
            $operation->add(Attribute::of('job-name', ValueTag::NameWithoutLanguage, $jobName));
        }

        return $this->request(Operation::PrintJob, $operation, $requestId, $jobAttributes, $data);
    }

    /**
     * Create-Job (§4.2.4): open a multi-document job; documents follow with
     * Send-Document.
     */
    public function createJob(
        string $printerUri,
        ?string $jobName = null,
        ?AttributeGroup $jobAttributes = null,
        ?int $requestId = null,
    ): Request {
        $operation = $this->operationGroup($printerUri);

        if ($jobName !== null) {
            $operation->add(Attribute::of('job-name', ValueTag::NameWithoutLanguage, $jobName));
        }

        return $this->request(Operation::CreateJob, $operation, $requestId, $jobAttributes);
    }

    /**
     * Send-Document (§4.3.1): add a document to a job opened with Create-Job.
     */
    public function sendDocument(
        string $printerUri,
        int $jobId,
        string $data,
        string $documentFormat = 'application/octet-stream',
        bool $lastDocument = true,
        ?int $requestId = null,
    ): Request {
        $operation = $this->operationGroup($printerUri);
        $operation->add(Attribute::of('job-id', ValueTag::Integer, $jobId));
        $operation->add(Attribute::of('document-format', ValueTag::MimeMediaType, $documentFormat));
        $operation->add(Attribute::of('last-document', ValueTag::Boolean, $lastDocument));

        return $this->request(Operation::SendDocument, $operation, $requestId, data: $data);
    }

    /**
     * Get-Job-Attributes (§4.3.4).
     *
     * @param  array<int,string>  $requested
     */
    public function getJobAttributes(string $printerUri, int $jobId, array $requested = [], ?int $requestId = null): Request
    {
        $operation = $this->operationGroup($printerUri);
        $operation->add(Attribute::of('job-id', ValueTag::Integer, $jobId));

        if ($requested !== []) {
            $operation->add(new Attribute('requested-attributes', ValueTag::Keyword, $requested));
        }

        return $this->request(Operation::GetJobAttributes, $operation, $requestId);
    }

    /**
     * Get-Jobs (§4.2.6). $which is 'not-completed' (default) or 'completed';
     * pass $myJobs to limit to the requesting user.
     *
     * @param  array<int,string>  $requested
     */
    public function getJobs(
        string $printerUri,
        array $requested = [],
        string $which = 'not-completed',
        ?int $limit = null,
        bool $myJobs = false,
        ?int $requestId = null,
    ): Request {
        $operation = $this->operationGroup($printerUri);
        $operation->add(Attribute::of('which-jobs', ValueTag::Keyword, $which));

        if ($myJobs) {
            $operation->add(Attribute::of('my-jobs', ValueTag::Boolean, true));
        }

        if ($limit !== null) {
            $operation->add(Attribute::of('limit', ValueTag::Integer, $limit));
        }

        if ($requested !== []) {
            $operation->add(new Attribute('requested-attributes', ValueTag::Keyword, $requested));
        }

        return $this->request(Operation::GetJobs, $operation, $requestId);
    }

    /**
     * Cancel-Job (§4.3.3).
     */
    public function cancelJob(string $printerUri, int $jobId, ?int $requestId = null): Request
    {
        $operation = $this->operationGroup($printerUri);
        $operation->add(Attribute::of('job-id', ValueTag::Integer, $jobId));

        return $this->request(Operation::CancelJob, $operation, $requestId);
    }

    /**
     * The mandatory head of every operation: charset and natural-language
     * first, then the target printer-uri and the requesting user.
     */
    private function operationGroup(string $printerUri): AttributeGroup
    {
        return new AttributeGroup(DelimiterTag::OperationAttributes, [
            Attribute::of('attributes-charset', ValueTag::Charset, $this->charset),
            Attribute::of('attributes-natural-language', ValueTag::NaturalLanguage, $this->naturalLanguage),
            Attribute::of('printer-uri', ValueTag::Uri, $printerUri),
            Attribute::of('requesting-user-name', ValueTag::NameWithoutLanguage, $this->requestingUser),
        ]);
    }

    private function request(
        Operation $operation,
        AttributeGroup $operationAttributes,
        ?int $requestId,
        ?AttributeGroup $jobAttributes = null,
        string $data = '',
    ): Request {
        $groups = [$operationAttributes];

        if ($jobAttributes instanceof AttributeGroup && $jobAttributes->attributes !== []) {
            $groups[] = $jobAttributes;
        }

        return new Request($operation, $requestId ?? $this->nextRequestId++, $groups, $data);
    }
}
