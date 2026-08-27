<?php

declare(strict_types=1);

namespace WALayer\Resource;

use WALayer\Idempotency;

/** The unified send route plus message reads. */
final class Messages extends AbstractResource
{
    /**
     * Send a message. `$message` is the envelope from §5.1 —
     * `['type' => …, 'to' => …, 'body' => [...], 'options' => [...]]` — and
     * accepts any of the 17 types in {@see \WALayer\MessageType}.
     *
     * An `Idempotency-Key` is generated when you omit one, so a retried call
     * never sends the same WhatsApp message twice. Pass your own
     * when you want to control it — and reuse the *same* key when retrying a
     * request whose outcome you did not see.
     *
     * Answers `202 Accepted`: acceptance, never delivery.
     *
     * @param  array<string,mixed> $message
     * @return array<string,mixed>
     */
    public function send(string $sessionId, array $message, ?string $idempotencyKey = null): array
    {
        /** @var array<string,mixed> */
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/messages',
            'POST',
            $message,
            ['idempotency-key' => $idempotencyKey ?? Idempotency::generate()]
        );
    }

    /**
     * Status plus the full event timeline for one message.
     *
     * @return array<string,mixed>
     */
    public function get(string $messageId): array
    {
        /** @var array<string,mixed> */
        return $this->http->request('/v1/messages/' . $this->seg($messageId));
    }

    /**
     * Explicit resend of an `undelivered` / `failed` message (§7).
     *
     * Nothing is ever auto-resent: this mints a NEW message, so calling it on
     * something that did in fact arrive delivers a duplicate to a real person.
     * It carries its own Idempotency-Key so that the *resend request* itself is
     * safe to retry.
     *
     * @return array<string,mixed>
     */
    public function resend(string $messageId, ?string $idempotencyKey = null): array
    {
        /** @var array<string,mixed> */
        return $this->http->request(
            '/v1/messages/' . $this->seg($messageId) . '/resend',
            'POST',
            null,
            ['idempotency-key' => $idempotencyKey ?? Idempotency::generate()]
        );
    }

    /**
     * A paced campaign. Recipients already on the opt-out list are dropped
     * before it starts, and the send drains at the number's warmup rate rather
     * than firing at once.
     *
     * @param  array<string,mixed>            $template
     * @param  array<int,array<string,mixed>> $recipients
     * @return array<string,mixed>
     */
    public function bulk(
        string $sessionId,
        array $template,
        array $recipients,
        ?string $name = null,
        ?string $idempotencyKey = null
    ): array {
        $body = ['template' => $template, 'recipients' => $recipients];
        if ($name !== null) {
            $body['name'] = $name;
        }

        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/messages/bulk',
            'POST',
            $body,
            ['idempotency-key' => $idempotencyKey ?? Idempotency::key()]
        );
    }

    /**
     * The message log.
     *
     * Returns the WHOLE envelope — `['data' => [...], 'next_cursor' => ...]` —
     * because unwrapping to the rows throws the cursor away, and a caller that
     * cannot see the cursor cannot read the second page.
     *
     * @param  array<string,mixed> $filters session, status, direction, type, q,
     *                                      from, to, limit, cursor
     * @return array<string,mixed>
     */
    public function list(array $filters = []): array
    {
        return $this->http->requestEnvelope('/v1/messages' . $this->query($filters));
    }

    /** Delivery / read receipts for a message. @return array<string,mixed> */
    public function receipts(string $messageId): array
    {
        return $this->http->request('/v1/messages/' . $this->seg($messageId) . '/receipts');
    }

    /**
     * Publish a status/story. `$story` is `['type' => 'text'|'image'|'video'|'audio',
     * 'body' => [...], 'options' => [...]]`.
     * @param array<string,mixed> $story @return array<string,mixed>
     */
    public function story(string $sessionId, array $story, ?string $idempotencyKey = null): array
    {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/stories',
            'POST',
            $story,
            ['idempotency-key' => $idempotencyKey ?? \WALayer\Idempotency::generate()]
        );
    }

    /** Star or unstar a message. @return array<string,mixed> */
    public function star(string $messageId, bool $starred = true): array
    {
        return $this->http->request('/v1/messages/' . $this->seg($messageId) . '/star', 'POST', ['starred' => $starred]);
    }

    /** Pin or unpin. `duration_seconds` ∈ {86400, 604800, 2592000}. @param array<string,mixed> $opts @return array<string,mixed> */
    public function pin(string $messageId, array $opts = []): array
    {
        return $this->http->request('/v1/messages/' . $this->seg($messageId) . '/pin', 'POST', $opts);
    }

    /** Send a read receipt for an inbound message. @return array<string,mixed> */
    public function markRead(string $messageId): array
    {
        return $this->http->request('/v1/messages/' . $this->seg($messageId) . '/read', 'POST');
    }

    /** Send a played receipt for an inbound voice note. @return array<string,mixed> */
    public function markPlayed(string $messageId): array
    {
        return $this->http->request('/v1/messages/' . $this->seg($messageId) . '/played', 'POST');
    }

}
