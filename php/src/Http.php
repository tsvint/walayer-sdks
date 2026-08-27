<?php

declare(strict_types=1);

namespace WALayer;

use InvalidArgumentException;

/**
 * Thin JSON transport over the WALayer REST API: adds the Bearer key, unwraps
 * the `{ "data": … }` envelope, and turns any non-2xx into a typed
 * {@see WALayerError} carrying the server's error code and request id — so
 * callers branch on `$e->code` instead of parsing bodies.
 *
 * @internal consumers use {@see WALayer} and its resource namespaces
 */
final class Http
{
    public const DEFAULT_BASE_URL = 'https://api.walayer.com';

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly Transport $transport;

    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE_URL, ?Transport $transport = null)
    {
        if ($apiKey === '') {
            throw new InvalidArgumentException('WALayer: apiKey is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = \rtrim($baseUrl, '/');
        $this->transport = $transport ?? new DefaultTransport();
    }

    /**
     * @param  array<string,mixed>|null $body         decoded body; JSON-encoded here
     * @param  array<string,string>     $extraHeaders
     * @return mixed                    the unwrapped `data` payload, or null for `204 No Content`
     *
     * @throws WALayerError   on any non-2xx response
     * @throws TransportError on a connection-level failure or an unparseable body
     */
    /**
     * The whole response envelope rather than just `data`.
     *
     * List endpoints answer `{"data": [...], "next_cursor": …}`. Unwrapping to
     * `data` discards the cursor, which leaves a caller able to read page one
     * and unable to ask for page two.
     *
     * @return array<string,mixed>
     */
    public function requestEnvelope(string $path, string $method = 'GET', ?array $body = null, array $extraHeaders = []): array
    {
        /** @var array<string,mixed> $out */
        $out = $this->request($path, $method, $body, $extraHeaders, false);

        return $out;
    }

    public function request(string $path, string $method = 'GET', ?array $body = null, array $extraHeaders = [], bool $unwrap = true): mixed
    {
        $headers = ['authorization' => 'Bearer ' . $this->apiKey];
        foreach ($extraHeaders as $name => $value) {
            $headers[\strtolower($name)] = $value;
        }

        $encoded = null;
        if ($body !== null) {
            $headers['content-type'] = 'application/json';
            $encoded = \json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                // Never echo the body — it is message content.
                throw new TransportError('WALayer: request body could not be encoded as JSON');
            }
        }

        $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $encoded);
        $requestId = $response->header('x-request-id');

        if ($response->status === 204) {
            return null;
        }

        $parsed = $this->decode($response->body, $response->status);

        if ($response->status >= 400) {
            $error = \is_array($parsed['error'] ?? null) ? $parsed['error'] : [];
            throw new WALayerError(
                $response->status,
                \is_string($error['code'] ?? null) ? $error['code'] : 'UNKNOWN',
                \is_string($error['message'] ?? null) ? $error['message'] : '',
                $error['detail'] ?? null,
                $requestId
            );
        }

        if (!$unwrap) {
            return $parsed;
        }

        return \array_key_exists('data', $parsed) ? $parsed['data'] : $parsed;
    }

    /** @return array<string,mixed> */
    private function decode(string $text, int $status): array
    {
        if (\trim($text) === '') {
            return [];
        }
        $parsed = \json_decode($text, true);
        if (!\is_array($parsed)) {
            // Deliberately excludes the body: it may carry message content.
            throw new TransportError(\sprintf('WALayer: malformed JSON in a %d response', $status));
        }

        return $parsed;
    }

    /**
     * Keeps the API key out of `var_dump()`, `print_r()` and any error handler
     * that dumps object state.
     *
     * @return array<string,string>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => '[redacted]', 'baseUrl' => $this->baseUrl, 'transport' => $this->transport::class];
    }
}
