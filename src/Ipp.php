<?php

declare(strict_types=1);

namespace Coyotito\Ipp;

/**
 * Entry point / version marker for the IPP package.
 *
 * The real protocol implementation (Protocol\Encoder/Decoder, Transport,
 * Capabilities, Discovery) and the public Printer/Discovery facades land in
 * Phase 1. This placeholder keeps the package autoloadable from day one.
 */
final class Ipp
{
    public const string VERSION = '0.1.0-dev';
}
