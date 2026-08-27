<?php

declare(strict_types=1);

namespace WALayer;

/**
 * Idempotency-Key generation.
 *
 * The key becomes `client_msg_id` with `UNIQUE (tenant_id, client_msg_id)`
 * server-side: a replayed request returns the original message
 * instead of sending a second real WhatsApp message to a real person. So the
 * key must be unpredictable and unique — this uses the CSPRNG, not `uniqid()`.
 */
final class Idempotency
{
    /** A RFC 4122 version-4 UUID. */
    public static function generate(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80); // RFC 4122 variant

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($bytes), 4));
    }

    private function __construct()
    {
    }
}
