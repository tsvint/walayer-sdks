<?php

declare(strict_types=1);

namespace WALayer;

if (!\function_exists('WALayer\verifyWebhook')) {
    /**
     * Function-style alias for {@see Webhook::verify()}, for handlers that would
     * rather not import a class.
     *
     * The `$timestamp` argument is not optional: the signed string is
     * `"{timestamp}.{rawBody}"` (docs/04 §8.3), so the `X-Timestamp` header is
     * an input to the HMAC, not metadata alongside it.
     *
     * @param string          $payload         the RAW request body
     * @param string|null     $signatureHeader the `X-Signature` header
     * @param int|string|null $timestamp       the `X-Timestamp` header (unix seconds)
     */
    function verifyWebhook(
        string $payload,
        ?string $signatureHeader,
        int|string|null $timestamp,
        string $secret,
        int $toleranceSeconds = Webhook::DEFAULT_TOLERANCE_SECONDS,
        ?int $nowSeconds = null
    ): VerifyResult {
        return Webhook::verify($payload, $signatureHeader, $timestamp, $secret, $toleranceSeconds, $nowSeconds);
    }
}
