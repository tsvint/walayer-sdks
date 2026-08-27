<?php

declare(strict_types=1);

namespace WALayer;

use OutOfBoundsException;
use RuntimeException;

/**
 * Thrown for any non-2xx API response, carrying the WALayer error code
 * rather than a stringified response body.
 *
 * Callers branch on the code, never on the message text:
 *
 *   catch (WALayerError $e) { if ($e->code === 'RECIPIENT_SUPPRESSED') { … } }
 *
 * `$e->code` is served by {@see WALayerError::__get()} because PHP's own
 * `Exception::$code` is an inherited int that cannot be redeclared as a
 * readonly string. `$e->errorCode` and `$e->getErrorCode()` are the direct,
 * non-magic equivalents; all three return the same value. The Node and Python
 * SDKs expose this field as `code`, so the alias keeps the three ports
 * interchangeable.
 */
final class WALayerError extends RuntimeException
{
    /** HTTP status of the failing response. */
    public readonly int $status;

    /** WALayer error code, e.g. `RECIPIENT_SUPPRESSED`. `UNKNOWN` when absent. */
    public readonly string $errorCode;

    /** Server-supplied structured detail (`error.detail`), or null. */
    public readonly mixed $detail;

    /** `X-Request-Id` of the failing response, for support tickets. */
    public readonly ?string $requestId;

    public function __construct(
        int $status,
        string $code,
        string $message,
        mixed $detail = null,
        ?string $requestId = null
    ) {
        // Exception's own int code stays 0: the meaningful codes here are the
        // string `errorCode` and the HTTP `status`.
        parent::__construct($code . ': ' . $message, 0);
        $this->status = $status;
        $this->errorCode = $code;
        $this->detail = $detail;
        $this->requestId = $requestId;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Serves `$e->code` — see the class docblock. */
    public function __get(string $name): mixed
    {
        if ($name === 'code') {
            return $this->errorCode;
        }

        throw new OutOfBoundsException(\sprintf('Undefined property %s::$%s', self::class, $name));
    }

    public function __isset(string $name): bool
    {
        return $name === 'code';
    }

    /**
     * Deliberately overrides the parent so the string form carries no stack
     * trace. PHP's default Exception::__toString() renders call arguments —
     * which for this SDK would mean API keys, webhook secrets and message
     * bodies landing in whatever log printed the exception.
     */
    public function __toString(): string
    {
        return \sprintf(
            '%s: [%d %s] %s',
            self::class,
            $this->status,
            $this->errorCode,
            $this->getMessage()
        );
    }
}
