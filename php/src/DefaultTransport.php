<?php

declare(strict_types=1);

namespace WALayer;

/**
 * The stock transport. Uses ext-curl when it is loaded and falls back to PHP's
 * stream wrappers otherwise, so the package needs no Composer dependencies at
 * runtime.
 */
final class DefaultTransport implements Transport
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 10
    ) {
    }

    /** @param array<string,string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        return \function_exists('curl_init')
            ? $this->sendViaCurl($method, $url, $headers, $body)
            : $this->sendViaStream($method, $url, $headers, $body);
    }

    /** @param array<string,string> $headers */
    private function sendViaCurl(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $responseHeaders = [];
        $ch = \curl_init();
        if ($ch === false) {
            throw new TransportError('WALayer: could not initialise curl');
        }

        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$responseHeaders): int {
                $len = \strlen($line);
                $parts = \explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[\strtolower(\trim($parts[0]))] = \trim($parts[1]);
                }

                return $len;
            },
        ]);
        if ($body !== null) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = \curl_exec($ch);
        if ($raw === false) {
            // curl_error carries no request headers, so no key material.
            $message = \curl_error($ch);
            \curl_close($ch);
            throw new TransportError('WALayer: request failed: ' . $message);
        }
        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        \curl_close($ch);

        return new TransportResponse($status, $responseHeaders, \is_string($raw) ? $raw : '');
    }

    /** @param array<string,string> $headers */
    private function sendViaStream(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $context = \stream_context_create([
            'http' => [
                'method' => $method,
                'header' => \implode("\r\n", $this->formatHeaders($headers)),
                'content' => $body ?? '',
                'timeout' => $this->timeoutSeconds,
                'follow_location' => 0,
                // Without this a 4xx becomes a PHP warning and `false`, losing
                // the error envelope the client needs to build a WALayerError.
                'ignore_errors' => true,
            ],
        ]);

        $raw = @\file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new TransportError('WALayer: request failed (stream transport)');
        }

        /** @var list<string> $rawHeaders */
        $rawHeaders = $http_response_header ?? [];

        return new TransportResponse($this->statusFrom($rawHeaders), $this->parseHeaders($rawHeaders), $raw);
    }

    /**
     * @param  array<string,string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[] = $name . ': ' . $value;
        }

        return $out;
    }

    /** @param list<string> $rawHeaders */
    private function statusFrom(array $rawHeaders): int
    {
        // Take the last status line so a proxy's 1xx/redirect preamble does not win.
        $status = 0;
        foreach ($rawHeaders as $line) {
            if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }

    /**
     * @param  list<string>         $rawHeaders
     * @return array<string,string>
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $out = [];
        foreach ($rawHeaders as $line) {
            $parts = \explode(':', $line, 2);
            if (\count($parts) === 2) {
                $out[\strtolower(\trim($parts[0]))] = \trim($parts[1]);
            }
        }

        return $out;
    }
}
