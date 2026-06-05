<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Enums;

/**
 * Begin-attribute-group / delimiter tags (RFC 8010 §3.5.1).
 *
 * These occupy the value range 0x00–0x0F and mark the boundaries between
 * the attribute groups inside an IPP message.
 */
enum DelimiterTag: int
{
    case OperationAttributes = 0x01;
    case JobAttributes = 0x02;
    case EndOfAttributes = 0x03;
    case PrinterAttributes = 0x04;
    case UnsupportedAttributes = 0x05;
    case SubscriptionAttributes = 0x06;   // RFC 3995
    case EventNotificationAttributes = 0x07; // RFC 3995
    case ResourceAttributes = 0x08;       // RFC 8011 (reserved/future)
    case DocumentAttributes = 0x09;       // RFC 3998
    case SystemAttributes = 0x0A;         // PWG 5100.22 (IPP System Service)

    /**
     * The end-of-attributes tag closes the attribute section; no group follows.
     */
    public function endsAttributes(): bool
    {
        return $this === self::EndOfAttributes;
    }
}
