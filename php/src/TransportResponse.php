<?php

declare(strict_types=1);

namespace WALayer;

/**
 * What a {@see Transport} hands back: the raw HTTP status, lower-cased response
 * headers and the undecoded body. Parsing is the Http layer's job so that every
 * transport — real or fake — behaves identically.
 */
final class TransportResponse
{
    /** @param array<string,string> $headers lower-cased header names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
