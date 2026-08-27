<?php

declare(strict_types=1);

namespace WALayer;

use RuntimeException;

/**
 * A connection-level failure: DNS, TLS, timeout, refused socket. Distinct from
 * {@see WALayerError}, which means the API answered and said no.
 *
 * The distinction matters for sends. A WALayerError is a decision; a
 * TransportError is an *unknown outcome* — the request may or may not have
 * reached the API. Retrying it blindly with a fresh Idempotency-Key can send a
 * real person the same WhatsApp message twice. Retry only with
 * the same key.
 */
final class TransportError extends RuntimeException
{
    /**
     * No trace in the string form: the trace would render call arguments, and
     * those include the API key and message bodies.
     */
    public function __toString(): string
    {
        return self::class . ': ' . $this->getMessage();
    }
}
