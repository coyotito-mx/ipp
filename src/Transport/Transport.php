<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Transport;

use Coyotito\Ipp\Exceptions\TransportException;

/**
 * Sends an encoded IPP request to a printer and returns the raw response bytes.
 *
 * Implementations are responsible only for the HTTP exchange (RFC 8010 §5):
 * POST the message with Content-Type `application/ipp` and return the body.
 * Encoding/decoding stays in the protocol layer, keeping this seam easy to
 * fake in tests or back with any HTTP stack.
 */
interface Transport
{
    /**
     * @param  string  $uri  Printer URI (`ipp://` or `ipps://`, default port 631).
     * @param  string  $message  Encoded IPP request bytes.
     * @return string Raw IPP response bytes.
     *
     * @throws TransportException
     */
    public function send(string $uri, string $message): string;
}
