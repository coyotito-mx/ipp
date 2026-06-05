<?php

declare(strict_types=1);

use Coyotito\Ipp\Exceptions\TransportException;
use Coyotito\Ipp\Transport\GuzzleTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * @param  array<int,Response>  $responses
 * @param  array<int,array<string,mixed>>  $history
 */
function guzzleTransport(array $responses, array &$history = []): GuzzleTransport
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new GuzzleTransport(new Client(['handler' => $stack]));
}

it('posts the IPP message and returns the response body', function () {
    $history = [];
    $transport = guzzleTransport([new Response(200, [], 'IPP-RESPONSE-BYTES')], $history);

    $body = $transport->send('ipp://printer.local:631/ipp/print', 'IPP-REQUEST-BYTES');

    expect($body)->toBe('IPP-RESPONSE-BYTES');

    $request = $history[0]['request'];
    expect((string) $request->getUri())->toBe('http://printer.local:631/ipp/print')
        ->and($request->getMethod())->toBe('POST')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/ipp')
        ->and((string) $request->getBody())->toBe('IPP-REQUEST-BYTES');
});

it('upgrades to TLS and retries on HTTP 426', function () {
    $history = [];
    $transport = guzzleTransport([
        new Response(426, [], ''),          // cleartext rejected
        new Response(200, [], 'OK-OVER-TLS'),
    ], $history);

    $body = $transport->send('ipp://printer.local:631/ipp/print', 'REQ');

    expect($body)->toBe('OK-OVER-TLS')
        ->and($history)->toHaveCount(2)
        ->and((string) $history[0]['request']->getUri())->toStartWith('http://')
        ->and((string) $history[1]['request']->getUri())->toBe('https://printer.local:631/ipp/print');
});

it('uses https directly for an ipps:// URI', function () {
    $history = [];
    $transport = guzzleTransport([new Response(200, [], 'OK')], $history);

    $transport->send('ipps://printer.local:631/ipp/print', 'REQ');

    expect((string) $history[0]['request']->getUri())->toBe('https://printer.local:631/ipp/print');
});

it('throws on a non-200 status', function () {
    $transport = guzzleTransport([new Response(500, [], '')]);

    expect(fn () => $transport->send('ipp://printer.local:631/ipp/print', 'REQ'))
        ->toThrow(TransportException::class);
});

it('adds basic auth when credentials are given', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], 'OK')]));
    $stack->push(Middleware::history($history));
    $transport = new GuzzleTransport(new Client(['handler' => $stack]), username: 'user', password: 'pass');

    $transport->send('ipp://printer.local:631/ipp/print', 'REQ');

    expect($history[0]['request']->getHeaderLine('Authorization'))
        ->toBe('Basic '.base64_encode('user:pass'));
});
