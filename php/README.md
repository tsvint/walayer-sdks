# walayer/walayer-php

Official PHP client for the [WALayer](https://walayer.com) WhatsApp API.
Zero runtime dependencies — PHP's standard library only.

## Install

```bash
composer require walayer/walayer-php
```

## Send a message in three lines

```php
use WALayer\WALayer;

$wa = new WALayer(getenv('WALAYER_API_KEY'));
$wa->messages->send($sessionId, ['type' => 'text', 'to' => '+94770000000', 'body' => ['text' => 'Hello 👋']]);
```

Every send is idempotent: the SDK generates an `Idempotency-Key` for you, so a
retried call never sends the same WhatsApp message twice. Pass your own as the
third argument when you want to control it — and reuse the **same** key when
retrying a request whose outcome you never saw.

A send answers `202 Accepted`. That is acceptance, not delivery; delivery
arrives as a `message.status` webhook.

## Resources

```php
$wa->sessions->list();
$wa->sessions->create('LK', 'Sales');
$wa->sessions->get($sessionId);
$wa->sessions->delete($sessionId);          // logout, credential shred, proxy release

$wa->messages->send($sessionId, $message);  // all 22 types — see below
$wa->messages->story($sessionId, ['type' => 'text', 'body' => ['text' => 'Live now']]);
$wa->messages->star($messageId);            // star / pin / read / played receipts
$wa->messages->receipts($messageId);

$wa->inbox->archiveChat($sessionId, $chatJid);
$wa->inbox->patchChat($sessionId, $chatJid, ['muted' => true, 'disappearing_seconds' => 604800]);

$wa->contacts->check($sessionId, ['+94770000000']);      // on-WhatsApp check
$wa->contacts->block($sessionId, $jid);
$wa->contacts->updateProfile($sessionId, ['about' => 'Back soon']);

$wa->groups->create($sessionId, 'Sales team', ['+94770000000']);
$wa->groups->settings($sessionId, $groupId, ['announce_only' => true]);
$wa->groups->requests($sessionId, $groupId);             // join-request review

$wa->communities->create($sessionId, ['name' => 'HQ']);
$wa->channels->create($sessionId, ['name' => 'Announcements']);
$wa->labels->create($sessionId, ['name' => 'VIP', 'color' => 2]);
$wa->business->rejectCall($sessionId, $callId, $callerJid);

$wa->webhooks->create('https://example.com/wa', ['message.status', 'message.inbound']);
$wa->webhooks->test($webhookId);            // fire a signed test delivery
$wa->events->types();                       // the event catalogue

$wa->suppressions->add('+94770000000');     // opt someone out
```

The developer API — and this SDK — is **WhatsApp actions only**. The platform
CRM, billing, usage and cross-tenant search are dashboard features; an API key is
refused there (`403`, `detail.surface = "dashboard"`).

## Message types

One route sends all 17 documented types. `WALayer\MessageType` has a constant for
each, so a typo is a fatal error instead of a `422`:

```php
use WALayer\MessageType;

$wa->messages->send($sessionId, [
    'type' => MessageType::IMAGE,
    'to'   => '+94770000000',
    'body' => ['media' => ['media_id' => 'med_…'], 'caption' => 'Your parcel'],
]);

$wa->messages->send($sessionId, [
    'type' => MessageType::POLL,
    'to'   => '+94770000000',
    'body' => ['question' => 'Preferred slot?', 'options' => ['Morning', 'Afternoon'], 'selectable' => 1],
]);
```

`text` · `image` · `video` · `audio` · `document` · `sticker` · `location` ·
`contact` · `reaction` · `poll` · `buttons` · `list` · `reply` · `forward` ·
`revoke` · `edit` · `presence`.

Media moves **by reference** — `['media_id' => 'med_…']` or `['url' => 'https://…']`,
never inline bytes.

## Errors

Any non-2xx response throws a typed `WALayerError` carrying the API's error code,
detail and request id — never a stringified body:

```php
use WALayer\WALayerError;

try {
    $wa->messages->send($sessionId, ['type' => 'text', 'to' => $to, 'body' => ['text' => $text]]);
} catch (WALayerError $e) {
    if ($e->code === 'RECIPIENT_SUPPRESSED') {
        // the recipient opted out — do not retry
    }
    $e->status;      // 409
    $e->detail;      // server-supplied structured detail, e.g. retry_after
    $e->requestId;   // X-Request-Id, for support
}
```

`$e->code` matches the Node and Python SDKs. It is an alias: PHP's inherited
`Exception::$code` is an int that cannot be redeclared, so the real property is
`$e->errorCode` (also `$e->getErrorCode()`). All three return the same string.

A connection-level failure (DNS, TLS, timeout) throws `WALayer\TransportError`
instead. The distinction matters: a `WALayerError` is a decision, a
`TransportError` is an **unknown outcome** — the send may or may not have landed.
Retry it only with the same `Idempotency-Key`.

Neither exception's message nor its string form contains your API key, your
webhook secret, or the message body.

## Verify webhooks

Verify against the **raw** request body — re-serialising the JSON breaks the HMAC:

```php
use WALayer\Webhook;

$result = Webhook::verify(
    file_get_contents('php://input'),          // raw body, never $_POST
    $_SERVER['HTTP_X_SIGNATURE'] ?? null,
    $_SERVER['HTTP_X_TIMESTAMP'] ?? null,
    getenv('WALAYER_WEBHOOK_SECRET'),
);

if (!$result->valid) {
    http_response_code(400);
    error_log('webhook rejected: ' . $result->reason);   // format | timestamp | signature
    exit;
}
```

A function alias is available if you prefer it: `WALayer\verifyWebhook(...)`,
same arguments.

The check is constant-time (`hash_equals`) and enforces the five-minute replay
window in both directions — a timestamp far in the past is a replay, one far in
the future is forged or badly skewed. Override with the fifth argument.

Delivery is **at-least-once and unordered**: dedupe on `X-Delivery-Id`, respond
`2xx` quickly, and process asynchronously.

## Configuration

```php
new WALayer(
    apiKey: 'wsk_live_…',                  // required
    baseUrl: 'https://api.walayer.com',    // optional; override for self-hosted
);
```

## Testing / custom transport

The HTTP transport is a one-method interface, so you can unit-test without a
network — or bridge an HTTP stack you already use, without the SDK depending on
one:

```php
use WALayer\{Transport, TransportResponse, WALayer};

$fake = new class implements Transport {
    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        return new TransportResponse(202, ['x-request-id' => 'req_1'], '{"data":{"id":"msg_1","status":"queued"}}');
    }
};

$wa = new WALayer('wsk_test_x', 'https://api.walayer.com', $fake);
```

The default transport uses ext-curl when present and PHP streams otherwise.

## Running the tests

```bash
php tests/run.php
```

The suite has no dependencies of its own: it uses a small PHPUnit-shaped
assertion base class and a plain runner that exits non-zero on failure.

Requires PHP 8.1+. MIT licensed.

## Scope: WhatsApp actions only

This SDK covers what you can do with a linked WhatsApp number:

| Namespace | What it does |
|---|---|
| `sessions` | link status, update pacing, health, logout, delete, on-WhatsApp check |
| `messages` | send, bulk campaigns, the message log (paged), fetch one, resend |
| `inbox` | chats, chat history, mark read, session contacts, block/unblock |
| `groups` | create, roster changes, invite link, join, leave, live metadata, joined list |
| `channels` | send to a WhatsApp Channel |
| `media` | upload (base64 or URL) and a short-lived read URL |
| `webhooks` · `events` | subscribe to, and replay, what WhatsApp reported |
| `suppressions` | the opt-out list that gates sends |

It deliberately does **not** wrap the platform's own surface — billing and
plans, the CRM (contact profiles, custom fields, tags, segments, timelines),
cross-tenant search, or the dashboard's cookie-authenticated routes. Those are
WALayer product features rather than WhatsApp ones, and an SDK that mixes them
teaches an integrator that "WhatsApp" and "our account system" are the same
thing. They remain reachable over plain REST.
