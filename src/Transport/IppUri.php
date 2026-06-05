<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Transport;

use Coyotito\Ipp\Exceptions\TransportException;

/**
 * Helpers for IPP printer URIs.
 */
final class IppUri
{
    /**
     * Translate an IPP printer URI to the HTTP(S) URL used on the wire:
     * `ipp://` → http and `ipps://` → https, default port 631.
     */
    public static function toHttpUrl(string $uri): string
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['host'])) {
            throw new TransportException("Invalid printer URI: {$uri}.");
        }

        $scheme = strtolower($parts['scheme'] ?? 'ipp');
        $httpScheme = in_array($scheme, ['ipps', 'https'], true) ? 'https' : 'http';
        $port = $parts['port'] ?? 631;
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return "{$httpScheme}://{$parts['host']}:{$port}{$path}{$query}";
    }
}
