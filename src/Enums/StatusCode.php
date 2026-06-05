<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Enums;

/**
 * IPP status codes (RFC 8011 §13) returned in the response message in place
 * of the request's operation-id.
 */
enum StatusCode: int
{
    // successful (0x0000–0x00FF)
    case SuccessfulOk = 0x0000;
    case SuccessfulOkIgnoredOrSubstitutedAttributes = 0x0001;
    case SuccessfulOkConflictingAttributes = 0x0002;

    // client-error (0x0400–0x04FF)
    case ClientErrorBadRequest = 0x0400;
    case ClientErrorForbidden = 0x0401;
    case ClientErrorNotAuthenticated = 0x0402;
    case ClientErrorNotAuthorized = 0x0403;
    case ClientErrorNotPossible = 0x0404;
    case ClientErrorTimeout = 0x0405;
    case ClientErrorNotFound = 0x0406;
    case ClientErrorGone = 0x0407;
    case ClientErrorRequestEntityTooLarge = 0x0408;
    case ClientErrorRequestValueTooLong = 0x0409;
    case ClientErrorDocumentFormatNotSupported = 0x040A;
    case ClientErrorAttributesOrValuesNotSupported = 0x040B;
    case ClientErrorUriSchemeNotSupported = 0x040C;
    case ClientErrorCharsetNotSupported = 0x040D;
    case ClientErrorConflictingAttributes = 0x040E;
    case ClientErrorCompressionNotSupported = 0x040F;
    case ClientErrorCompressionError = 0x0410;
    case ClientErrorDocumentFormatError = 0x0411;
    case ClientErrorDocumentAccessError = 0x0412;

    // server-error (0x0500–0x05FF)
    case ServerErrorInternalError = 0x0500;
    case ServerErrorOperationNotSupported = 0x0501;
    case ServerErrorServiceUnavailable = 0x0502;
    case ServerErrorVersionNotSupported = 0x0503;
    case ServerErrorDeviceError = 0x0504;
    case ServerErrorTemporaryError = 0x0505;
    case ServerErrorNotAcceptingJobs = 0x0506;
    case ServerErrorBusy = 0x0507;
    case ServerErrorJobCanceled = 0x0508;
    case ServerErrorMultipleDocumentJobsNotSupported = 0x0509;

    /**
     * 0x0000–0x00FF: the request succeeded (possibly with substitutions).
     */
    public function isSuccessful(): bool
    {
        return $this->value <= 0x00FF;
    }

    /**
     * 0x0400–0x04FF: the request was rejected because of the client.
     */
    public function isClientError(): bool
    {
        return $this->value >= 0x0400 && $this->value <= 0x04FF;
    }

    /**
     * 0x0500–0x05FF: the request failed on the printer/server side.
     */
    public function isServerError(): bool
    {
        return $this->value >= 0x0500 && $this->value <= 0x05FF;
    }

    /**
     * Human-readable keyword form, e.g. "successful-ok",
     * "client-error-not-found" — matches the names used by ipptool.
     */
    public function keyword(): string
    {
        $name = preg_replace('/(?<!^)[A-Z]/', '-$0', $this->name);

        return strtolower((string) $name);
    }
}
