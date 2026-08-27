<?php

declare(strict_types=1);

namespace WALayer\Resource;

/**
 * Event history — the self-serve recovery path when your endpoint was down
 * (docs/04 §8.1). Webhook delivery is at-least-once and unordered, so dedupe on
 * `X-Delivery-Id` rather than assuming you see each event once.
 */
final class Events extends AbstractResource
{
    /**
     * @param  int|null                 $since unix seconds (D17 — seconds everywhere)
     * @return list<array<string,mixed>>
     */
    public function list(?int $since = null, ?int $limit = null): array
    {
        /** @var list<array<string,mixed>> */
        return $this->http->request('/v1/events' . $this->query(['since' => $since, 'limit' => $limit]));
    }

    /**
     * Re-deliver one event.
     *
     * Delivery is at-least-once and carries `X-Delivery-Id`, so a redelivery
     * your handler has already processed is safe to ignore — provided the
     * handler keys on that id.
     *
     * @return array<string,mixed>
     */
    public function redeliver(string $eventId): array
    {
        return $this->http->request('/v1/events/' . $this->seg($eventId) . '/redeliver', 'POST');
    }

    /** The event catalogue. @return array<string,mixed> */
    public function types(): array
    {
        return $this->http->request('/v1/events/types');
    }

}
