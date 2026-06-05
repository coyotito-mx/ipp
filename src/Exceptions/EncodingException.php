<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Exceptions;

/**
 * Thrown when a message cannot be serialized to the IPP wire format.
 */
final class EncodingException extends IppException {}
