<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Exceptions;

/**
 * Thrown when printer discovery fails (the discovery tool is missing or a
 * discovered record cannot be parsed).
 */
final class DiscoveryException extends IppException {}
