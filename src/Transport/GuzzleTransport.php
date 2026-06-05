<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Transport;

use Coyotito\Ipp\Exceptions\TransportException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Default {@see Transport}, backed by any PSR-18 HTTP client (Guzzle by
 * default). Because it depends only on the PSR-18 interface, the client is
 * fully swappable — inject Guzzle with a `MockHandler` in tests, a preconfigured
 * Guzzle client (proxy, custom CA), or any other PSR-18 implementation.
 *
 * Maps `ipp://` → http and `ipps://` → https (port 631 by default). For real
 * network printers: TLS verification defaults to OFF (self-signed certs), and
 * on HTTP 426 over cleartext it retries once over TLS (many IPP-Everywhere
 * printers advertise `ipp://` yet require https).
 */
final class GuzzleTransport implements Transport
{
    private readonly ClientInterface $client;

    public function __construct(
        ?ClientInterface $client = null,
        private readonly bool $upgradeInsecure = true,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        float $timeout = 30.0,
        bool $verifyTls = false,
    ) {
        $this->client = $client ?? new Client([
            'timeout' => $timeout,
            'connect_timeout' => 5.0,
            'verify' => $verifyTls,
        ]);
    }

    public function send(string $uri, string $message): string
    {
        $url = IppUri::toHttpUrl($uri);

        $response = $this->dispatch($url, $message);
        $status = $response->getStatusCode();

        // Some printers advertise ipp:// but reject cleartext and ask to upgrade.
        if ($status === 426 && $this->upgradeInsecure && str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, strlen('http://'));
            $response = $this->dispatch($url, $message);
            $status = $response->getStatusCode();
        }

        if ($status !== 200) {
            throw new TransportException("Printer returned HTTP {$status} for {$url}.");
        }

        return (string) $response->getBody();
    }

    private function dispatch(string $url, string $message): ResponseInterface
    {
        $headers = [
            'Content-Type' => 'application/ipp',
            'Accept' => 'application/ipp',
        ];

        if ($this->username !== null) {
            $headers['Authorization'] = 'Basic '.base64_encode("{$this->username}:{$this->password}");
        }

        try {
            return $this->client->sendRequest(new Request('POST', $url, $headers, $message));
        } catch (ClientExceptionInterface $e) {
            throw new TransportException("Transport error for {$url}: {$e->getMessage()}.", previous: $e);
        }
    }
}
