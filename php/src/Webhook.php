<?php

declare(strict_types=1);

namespace WALayer;

/**
 * Webhook signature verification.
 *
 * Matches the server scheme (docs/04-api-spec.md §8.3): the request carries
 * `X-Signature: v1,sha256=<hex>` and `X-Timestamp: <unix seconds>`, where
 * hex = HMAC-SHA256(secret, "{timestamp}.{rawBody}").
 *
 * Verify against the RAW body — re-serialising the JSON breaks the HMAC.
 */
final class Webhook
{
    public const SIGNATURE_SCHEME = 'v1';
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    private const PREFIX = self::SIGNATURE_SCHEME . ',sha256=';

    /**
     * Constant-time verification with a replay window enforced in both
     * directions: a timestamp far in the past is a replay, one far in the
     * future is a forged or badly-skewed sender. Neither is accepted.
     *
     * @param string          $rawBody          the request body exactly as received
     * @param string|null     $signature        the `X-Signature` header
     * @param int|string|null $timestamp        the `X-Timestamp` header (unix seconds)
     * @param string          $secret           the endpoint's signing secret
     * @param int             $toleranceSeconds half-width of the accepted window
     * @param int|null        $nowSeconds       override for tests; defaults to time()
     */
    public static function verify(
        string $rawBody,
        ?string $signature,
        int|string|null $timestamp,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $nowSeconds = null
    ): VerifyResult {
        if ($signature === null || !\str_starts_with($signature, self::PREFIX)) {
            return VerifyResult::fail(VerifyResult::REASON_FORMAT);
        }

        if (!\is_int($timestamp) && (!\is_string($timestamp) || !\preg_match('/^-?\d+$/', $timestamp))) {
            return VerifyResult::fail(VerifyResult::REASON_TIMESTAMP);
        }
        $ts = (int) $timestamp;
        $now = $nowSeconds ?? \time();
        // Both directions: stale replays AND timestamps from the future.
        if (\abs($now - $ts) > $toleranceSeconds) {
            return VerifyResult::fail(VerifyResult::REASON_TIMESTAMP);
        }

        $expected = self::PREFIX . \hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
        if (!\hash_equals($expected, $signature)) {
            return VerifyResult::fail(VerifyResult::REASON_SIGNATURE);
        }

        return VerifyResult::ok();
    }

    /**
     * Sign a payload the way the server does. Present so tests and local
     * webhook replays can produce a real header; production code only ever
     * needs {@see Webhook::verify()}.
     */
    public static function sign(string $rawBody, int $timestamp, string $secret): string
    {
        return self::PREFIX . \hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }
}
