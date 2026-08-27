<?php

declare(strict_types=1);

namespace WALayer\Tests;

use WALayer\Transport;
use WALayer\TransportResponse;

/**
 * Records every call and answers with a canned envelope, so the whole client is
 * exercised without a network — the point of the injectable transport seam.
 *
 * @phpstan-type Call array{method:string,url:string,headers:array<string,string>,body:array<string,mixed>|null}
 */
final class RecordingTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:array<string,mixed>|null}> */
    public array $calls = [];

    public function __construct(
        private readonly int $status = 200,
        private readonly mixed $payload = null
    ) {
    }

    /** @param array<string,string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $decoded = $body === null ? null : \json_decode($body, true);
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => \is_array($decoded) ? $decoded : null,
        ];

        // 204 must not carry a body.
        $text = $this->status === 204 ? '' : (string) \json_encode($this->payload);

        return new TransportResponse($this->status, ['x-request-id' => 'req_1'], $text);
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:array<string,mixed>|null} */
    public function lastCall(): array
    {
        $call = \end($this->calls);
        if ($call === false) {
            throw new \RuntimeException('no calls recorded');
        }

        return $call;
    }
}
