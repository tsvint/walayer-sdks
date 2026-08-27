<?php

declare(strict_types=1);

namespace WALayer\Resource;

/** Business reads, calls and bots. */
final class Business extends AbstractResource
{
    /** A business profile — the linked number's own, or `$jid`. @return array<string,mixed> */
    public function profile(string $sessionId, ?string $jid = null): array
    {
        $q = $jid !== null ? $this->query(['jid' => $jid]) : '';
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/business/profile' . $q);
    }

    /** Items of an order message. @return array<string,mixed> */
    public function order(string $sessionId, string $orderId, string $token): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/business/orders/' . $this->seg($orderId) . $this->query(['token' => $token]));
    }

    /** Resolve a wa.me business link. @return array<string,mixed> */
    public function resolveLink(string $sessionId, string $code): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/business/link/' . $this->seg($code));
    }

    /** Reject an incoming call. @return array<string,mixed> */
    public function rejectCall(string $sessionId, string $callId, string $callerJid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/calls/' . $this->seg($callId) . '/reject', 'POST', ['caller_jid' => $callerJid]);
    }

    /** Meta AI bots visible to this number. @return array<int,mixed> */
    public function bots(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/bots');
    }
}
