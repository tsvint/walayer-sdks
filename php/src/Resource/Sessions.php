<?php

declare(strict_types=1);

namespace WALayer\Resource;

/** Sessions — one linked WhatsApp number each (docs/04-api-spec.md §3.1). */
final class Sessions extends AbstractResource
{
    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        /** @var list<array<string,mixed>> */
        return $this->http->request('/v1/sessions');
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId): array
    {
        /** @var array<string,mixed> */
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId));
    }

    /**
     * Create a session. A proxy is allocated at creation; if none is available
     * the API refuses rather than falling back to shared egress (invariant I3).
     *
     * @return array<string,mixed>
     */
    public function create(string $country, ?string $label = null): array
    {
        $body = ['country' => $country];
        if ($label !== null) {
            $body['label'] = $label;
        }

        /** @var array<string,mixed> */
        return $this->http->request('/v1/sessions', 'POST', $body);
    }

    /** Delete: graceful logout, credential shred, proxy release. */
    public function delete(string $sessionId): void
    {
        $this->http->request('/v1/sessions/' . $this->seg($sessionId), 'DELETE');
    }

    /**
     * Partial update. `pacing` is MERGED, not replaced: a patch that mentions
     * one key must not silently drop the others (docs/04 §4.4).
     *
     * @param  array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function update(string $sessionId, array $patch): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId), 'PATCH', $patch);
    }

    /** @return array<string,mixed> */
    public function logout(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/logout', 'POST');
    }

    /**
     * Trust score, warmup stage and delivery stats — the health card (FR-033).
     *
     * @return array<string,mixed>
     */
    public function health(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/health');
    }

    /**
     * Which of these numbers are on WhatsApp?
     *
     * Capped at 50 per call. That cap is the point: without it this endpoint is
     * a number-validity oracle, which is exactly what list scrapers want.
     *
     * @param  array<int,string>   $phones
     * @return array<string,mixed>
     */
    public function onWhatsApp(string $sessionId, array $phones): array
    {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/on-whatsapp',
            'POST',
            ['phones' => $phones]
        );
    }

    /** The settable half of the session (label, pacing, caps, warmup). @return array<string,mixed> */
    public function settings(string $id): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($id) . '/settings');
    }

    /** Reset settings to plan defaults. Warmup stage never resets up. @return array<string,mixed> */
    public function resetSettings(string $id): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($id) . '/settings/reset', 'POST');
    }

    /** Current send caps & warmup limits. @return array<string,mixed> */
    public function limits(string $id): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($id) . '/limits');
    }

    /** Start QR / phone-code pairing. @param array<string,mixed> $opts @return array<string,mixed> */
    public function pair(string $id, array $opts = []): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($id) . '/pair', 'POST', $opts);
    }

    /** Rotate the pinned egress proxy. @return array<string,mixed> */

}
