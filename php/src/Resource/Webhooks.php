<?php

declare(strict_types=1);

namespace WALayer\Resource;

/** Webhook endpoints — many per tenant or per session (docs/04 §3.5). */
final class Webhooks extends AbstractResource
{
    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        /** @var list<array<string,mixed>> */
        return $this->http->request('/v1/webhooks');
    }

    /**
     * @param  list<string>        $events
     * @return array<string,mixed>
     */
    public function create(string $url, array $events, ?string $sessionId = null): array
    {
        $body = ['url' => $url, 'events' => $events];
        if ($sessionId !== null) {
            $body['session_id'] = $sessionId;
        }

        /** @var array<string,mixed> */
        return $this->http->request('/v1/webhooks', 'POST', $body);
    }

    /**
     * Partial update. Settable: `url`, `events`, `status`.
     *
     * @param  array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function update(string $webhookId, array $patch): array
    {
        /** @var array<string,mixed> */
        return $this->http->request('/v1/webhooks/' . $this->seg($webhookId), 'PATCH', $patch);
    }

    public function delete(string $webhookId): void
    {
        $this->http->request('/v1/webhooks/' . $this->seg($webhookId), 'DELETE');
    }

    /** Fire a signed test delivery at the endpoint now. @return array<string,mixed> */
    public function test(string $id): array
    {
        return $this->http->request('/v1/webhooks/' . $this->seg($id) . '/test', 'POST');
    }

}
