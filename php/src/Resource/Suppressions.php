<?php

declare(strict_types=1);

namespace WALayer\Resource;

/**
 * The tenant's opt-out list (STOP handling, docs/04 §3.3).
 *
 * A suppressed recipient is refused at send time with `409
 * RECIPIENT_SUPPRESSED`. Suppressions outlive message retention on purpose —
 * one that expired with the message that caused it would let someone who said
 * STOP be messaged again.
 */
final class Suppressions extends AbstractResource
{
    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        /** @var list<array<string,mixed>> */
        return $this->http->request('/v1/suppressions');
    }

    /** @return array<string,mixed> */
    public function add(string $phone, ?string $reason = null): array
    {
        $body = ['phone' => $phone];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        /** @var array<string,mixed> */
        return $this->http->request('/v1/suppressions', 'POST', $body);
    }

    public function remove(string $phone): void
    {
        $this->http->request('/v1/suppressions/' . $this->seg($phone), 'DELETE');
    }
}
