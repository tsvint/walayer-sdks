<?php

declare(strict_types=1);

namespace WALayer\Tests;

use WALayer\VerifyResult;
use WALayer\Webhook;

use function WALayer\verifyWebhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_abc';

    private function sign(int $ts, string $body, string $secret = self::SECRET): string
    {
        return 'v1,sha256=' . \hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    public function testAcceptsAValidSignatureInsideTheWindow(): void
    {
        $ts = 1700000000;
        $body = '{"event":"message.status"}';
        $r = Webhook::verify($body, $this->sign($ts, $body), $ts, self::SECRET, 300, $ts + 5);
        $this->assertTrue($r->valid);
        $this->assertNull($r->reason);
    }

    public function testRejectsATamperedBody(): void
    {
        $ts = 1700000000;
        $signature = $this->sign($ts, '{"a":1}');
        $r = Webhook::verify('{"a":2}', $signature, $ts, self::SECRET, 300, $ts);
        $this->assertFalse($r->valid);
        $this->assertSame(VerifyResult::REASON_SIGNATURE, $r->reason);
    }

    public function testRejectsTheWrongSecret(): void
    {
        $ts = 1700000000;
        $body = '{"event":"message.status"}';
        $r = Webhook::verify($body, $this->sign($ts, $body, 'whsec_other'), $ts, self::SECRET, 300, $ts);
        $this->assertFalse($r->valid);
        $this->assertSame(VerifyResult::REASON_SIGNATURE, $r->reason);
    }

    public function testRejectsAStaleTimestamp(): void
    {
        $ts = 1700000000;
        $body = '{}';
        $r = Webhook::verify($body, $this->sign($ts, $body), $ts, self::SECRET, 300, $ts + 10000);
        $this->assertFalse($r->valid);
        $this->assertSame(VerifyResult::REASON_TIMESTAMP, $r->reason);
    }

    public function testRejectsATimestampFromTheFuture(): void
    {
        // The replay window is enforced in BOTH directions: a sender claiming a
        // timestamp well ahead of now is forged or badly skewed, not fresh.
        $ts = 1700000000;
        $body = '{}';
        $r = Webhook::verify($body, $this->sign($ts, $body), $ts, self::SECRET, 300, $ts - 10000);
        $this->assertFalse($r->valid);
        $this->assertSame(VerifyResult::REASON_TIMESTAMP, $r->reason);
    }

    public function testWindowBoundariesAreSymmetric(): void
    {
        $ts = 1700000000;
        $body = '{}';
        $signature = $this->sign($ts, $body);

        $this->assertTrue(Webhook::verify($body, $signature, $ts, self::SECRET, 300, $ts + 300)->valid, 'past edge');
        $this->assertTrue(Webhook::verify($body, $signature, $ts, self::SECRET, 300, $ts - 300)->valid, 'future edge');
        $this->assertFalse(Webhook::verify($body, $signature, $ts, self::SECRET, 300, $ts + 301)->valid, 'past +1');
        $this->assertFalse(Webhook::verify($body, $signature, $ts, self::SECRET, 300, $ts - 301)->valid, 'future +1');
    }

    public function testRejectsAMalformedOrMissingHeader(): void
    {
        $this->assertSame(
            VerifyResult::REASON_FORMAT,
            Webhook::verify('{}', 'sha256=deadbeef', 1, self::SECRET, 300, 1)->reason
        );
        $this->assertSame(
            VerifyResult::REASON_FORMAT,
            Webhook::verify('{}', null, 1, self::SECRET, 300, 1)->reason
        );
        $this->assertSame(
            VerifyResult::REASON_FORMAT,
            Webhook::verify('{}', 'v2,sha256=deadbeef', 1, self::SECRET, 300, 1)->reason
        );
    }

    public function testRejectsAMissingOrNonNumericTimestamp(): void
    {
        $ts = 1700000000;
        $body = '{}';
        $signature = $this->sign($ts, $body);

        $this->assertSame(
            VerifyResult::REASON_TIMESTAMP,
            Webhook::verify($body, $signature, null, self::SECRET, 300, $ts)->reason
        );
        $this->assertSame(
            VerifyResult::REASON_TIMESTAMP,
            Webhook::verify($body, $signature, 'not-a-number', self::SECRET, 300, $ts)->reason
        );
    }

    public function testAcceptsAStringTimestampHeader(): void
    {
        // Header values arrive as strings from every PHP SAPI.
        $ts = 1700000000;
        $body = '{}';
        $r = Webhook::verify($body, $this->sign($ts, $body), (string) $ts, self::SECRET, 300, $ts);
        $this->assertTrue($r->valid);
    }

    public function testTheFunctionAliasBehavesIdentically(): void
    {
        $ts = 1700000000;
        $body = '{"event":"message.status"}';
        $this->assertTrue(verifyWebhook($body, $this->sign($ts, $body), $ts, self::SECRET, 300, $ts)->valid);
        $this->assertFalse(verifyWebhook($body, $this->sign($ts, 'other'), $ts, self::SECRET, 300, $ts)->valid);
    }

    public function testSignHelperRoundTrips(): void
    {
        $ts = 1700000000;
        $body = '{"event":"message.status"}';
        $this->assertTrue(Webhook::verify($body, Webhook::sign($body, $ts, self::SECRET), $ts, self::SECRET, 300, $ts)->valid);
    }

    // ------------------------------------------------------- cross-language

    /**
     * Cross-language guard: these signatures were produced by the Node SDK's
     * own algorithm — createHmac("sha256", secret).update(`${ts}.${rawBody}`) —
     * and are pinned here as literals. If this PHP implementation ever drifts
     * from the documented formula (docs/04 §8.3) these stop verifying, even
     * though every self-signed test above would still pass.
     */
    public function testVerifiesSignaturesProducedByTheNodeSdk(): void
    {
        $secret = 'whsec_test_key';
        $ts = 1700000000;

        $body = '{"event":"message.status","id":"evt_01j","data":{"message_id":"msg_01j","status":"delivered"}}';
        $nodeSignature = 'v1,sha256=51393e42b0ca206d1a1e0f7e770c9fa5d3a4ceeee9b9695f2b65d6c18f15cc7f';
        $this->assertTrue(
            Webhook::verify($body, $nodeSignature, $ts, $secret, 300, $ts + 3)->valid,
            'PHP failed to verify a Node-produced signature'
        );
        $this->assertSame($nodeSignature, Webhook::sign($body, $ts, $secret), 'PHP signer disagrees with Node');
    }

    public function testVerifiesANodeSignatureOverAMultiByteBody(): void
    {
        // Emoji and an em dash: the HMAC is over raw bytes, so a mb_* slip here
        // would show up as a mismatch rather than silently passing.
        $secret = 'whsec_test_key';
        $ts = 1700000000;
        $body = '{"event":"message.inbound","text":"Hello 👋 — ñ"}';
        $nodeSignature = 'v1,sha256=01a78641a5aad08a3fb716decd5a720c8a87a0a7711a7572c49f822af6c4593d';

        $this->assertTrue(
            Webhook::verify($body, $nodeSignature, $ts, $secret, 300, $ts)->valid,
            'PHP failed to verify a Node-produced signature over a multi-byte body'
        );
    }

    public function testDefaultToleranceIsFiveMinutes(): void
    {
        $this->assertSame(300, Webhook::DEFAULT_TOLERANCE_SECONDS);
    }
}
