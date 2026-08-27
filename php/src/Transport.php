<?php

declare(strict_types=1);

namespace WALayer;

/**
 * The HTTP seam. Injectable so tests run without a network and so an app that
 * already standardises on Guzzle/Symfony HttpClient can bridge its own stack in
 * without the SDK depending on one — the same pattern as the Node SDK's
 * injectable `fetch` and the Python SDK's `transport` callable.
 */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @param string|null          $body    already-encoded request body, or null
     *
     * @throws TransportError on a connection-level failure (never on a non-2xx status)
     */
    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse;
}
