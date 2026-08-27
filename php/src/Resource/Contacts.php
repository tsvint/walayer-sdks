<?php

declare(strict_types=1);

namespace WALayer\Resource;

/**
 * WhatsApp identity, presence and contact checks — distinct from the platform
 * CRM (which is not part of the developer API). These act on the linked number
 * and its WhatsApp contacts.
 */
final class Contacts extends AbstractResource
{
    /** Are these numbers on WhatsApp? Capped at 50. @param array<int,string> $phones @return array<string,mixed> */
    public function check(string $sessionId, array $phones): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts/check', 'POST', ['phones' => $phones]);
    }

    /** Resolve LID ↔ phone JID. @param array<int,string> $jids @return array<string,mixed> */
    public function resolve(string $sessionId, array $jids): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts/resolve', 'POST', ['jids' => $jids]);
    }

    /** @return array<string,mixed> */
    public function block(string $sessionId, string $jid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid) . '/block', 'POST');
    }

    /** @return array<string,mixed> */
    public function unblock(string $sessionId, string $jid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid) . '/unblock', 'POST');
    }

    /** Recorded + live blocklist. @return array<string,mixed> */
    public function blocklist(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/blocklist');
    }

    /** @return array<string,mixed> */
    public function about(string $sessionId, string $jid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid) . '/about');
    }

    /** @return array<string,mixed> */
    public function profile(string $sessionId, string $jid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid) . '/profile');
    }

    /** Last observed presence. @return array<string,mixed> */
    public function presence(string $sessionId, string $jid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid) . '/presence');
    }

    /** @return array<string,mixed> */
    public function subscribePresence(string $sessionId, string $jid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid) . '/presence/subscribe', 'POST');
    }

    /** The linked number's OWN profile. @return array<string,mixed> */
    public function ownProfile(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/profile');
    }

    /** Update the linked number's push name / about. @param array<string,mixed> $input @return array<string,mixed> */
    public function updateProfile(string $sessionId, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/profile', 'PATCH', $input);
    }

    /** Set own online / offline presence. @return array<string,mixed> */
    public function setPresence(string $sessionId, string $state): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/presence', 'POST', ['state' => $state]);
    }
}
