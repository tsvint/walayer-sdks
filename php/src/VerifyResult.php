<?php

declare(strict_types=1);

namespace WALayer;

/**
 * Outcome of a webhook signature check. Structured rather than a bare bool so a
 * handler can log *why* it rejected — a run of `timestamp` failures means clock
 * skew, a run of `signature` failures means the wrong secret.
 */
final class VerifyResult
{
    public const REASON_FORMAT = 'format';
    public const REASON_TIMESTAMP = 'timestamp';
    public const REASON_SIGNATURE = 'signature';

    private function __construct(
        public readonly bool $valid,
        /** One of the REASON_* constants, or null when valid. */
        public readonly ?string $reason = null
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
