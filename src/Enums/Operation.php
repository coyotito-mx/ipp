<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Enums;

/**
 * IPP operation identifiers (RFC 8011 §5.4.15, "operations-supported").
 *
 * The numeric value is the 2-byte operation-id placed in a request message.
 */
enum Operation: int
{
    case PrintJob = 0x0002;
    case PrintUri = 0x0003;
    case ValidateJob = 0x0004;
    case CreateJob = 0x0005;
    case SendDocument = 0x0006;
    case SendUri = 0x0007;
    case CancelJob = 0x0008;
    case GetJobAttributes = 0x0009;
    case GetJobs = 0x000A;
    case GetPrinterAttributes = 0x000B;
    case HoldJob = 0x000C;
    case ReleaseJob = 0x000D;
    case RestartJob = 0x000E;
    case PausePrinter = 0x0010;
    case ResumePrinter = 0x0011;
    case PurgeJobs = 0x0012;
}
