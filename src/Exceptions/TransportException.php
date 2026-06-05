<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Exceptions;

/**
 * Thrown when the HTTP exchange with the printer fails (connection error,
 * timeout, or a non-200 status).
 */
final class TransportException extends IppException {}
