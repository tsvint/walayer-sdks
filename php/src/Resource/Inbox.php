<?php

declare(strict_types=1);

namespace WALayer\Resource;

/**
 * WhatsApp conversations on a linked number: what came in, what you replied,
 * and the contact-level actions WhatsApp itself supports.
 */
final class Inbox extends AbstractResource
{
    /**
     * Conversations, newest first.
     *
     * `q` matches the contact's name, a group's subject, or the number — the
     * three things a person can actually see in the list.
     *
     * @return array<int,array<string,mixed>>
     */
    public function chats(
        string $sessionId,
        ?string $q = null,
        ?bool $unread = null,
        ?string $kind = null,
        ?int $limit = null
    ): array {
        $qs = $this->query([
            'q' => $q,
            'unread' => $unread === null ? null : ($unread ? 'true' : 'false'),
            'kind' => $kind,
            'limit' => $limit,
        ]);

        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/chats' . $qs);
    }

    /**
     * One conversation, oldest first — the order a thread reads in. `q`
     * searches within it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function messages(string $sessionId, string $chatJid, ?string $q = null, ?int $limit = null): array
    {
        $qs = $this->query(['q' => $q, 'limit' => $limit]);

        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/chats/' . $this->seg($chatJid) . '/messages' . $qs
        );
    }

    public function markRead(string $sessionId, string $chatJid): void
    {
        $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/chats/' . $this->seg($chatJid) . '/read',
            'POST'
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function contacts(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/contacts');
    }

    /**
     * @param  array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function upsertContact(string $sessionId, string $jid, array $fields = []): array
    {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid),
            'PUT',
            $fields
        );
    }

    /**
     * Block a contact on THIS session.
     *
     * WhatsApp's blocklist belongs to the linked account, so blocking on one of
     * a tenant's numbers does not mute the contact on the others. A `202` means
     * the intent is recorded, not that WhatsApp has performed it yet.
     *
     * @return array<string,mixed>
     */
    public function block(string $sessionId, string $jid): array
    {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid) . '/block',
            'POST'
        );
    }

    /** @return array<string,mixed> */
    public function unblock(string $sessionId, string $jid): array
    {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/contacts/' . $this->seg($jid) . '/unblock',
            'POST'
        );
    }

    /** One chat with its state. @return array<string,mixed> */
    public function getChat(string $sessionId, string $chatJid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/chats/' . $this->seg($chatJid));
    }

    /** Delete a chat. @return array<string,mixed> */
    public function deleteChat(string $sessionId, string $chatJid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/chats/' . $this->seg($chatJid), 'DELETE');
    }

    /** Archive or unarchive a chat. @return array<string,mixed> */
    public function archiveChat(string $sessionId, string $chatJid, bool $archived = true): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/chats/' . $this->seg($chatJid) . '/archive', 'POST', ['archived' => $archived]);
    }

    /** Pin / mute / unread / disappearing timer. @param array<string,mixed> $patch @return array<string,mixed> */
    public function patchChat(string $sessionId, string $chatJid, array $patch): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/chats/' . $this->seg($chatJid), 'PATCH', $patch);
    }

    /** Send typing / recording / paused presence into a chat. @return array<string,mixed> */
    public function presence(string $sessionId, string $chatJid, string $state): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/chats/' . $this->seg($chatJid) . '/presence', 'POST', ['state' => $state]);
    }

}
