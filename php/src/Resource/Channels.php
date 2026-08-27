<?php

declare(strict_types=1);

namespace WALayer\Resource;

use WALayer\Idempotency;

/** WhatsApp Channels (newsletters) — broadcast, not conversation. */
final class Channels extends AbstractResource
{
    /**
     * @param  array<string,mixed> $message
     * @return array<string,mixed>
     */
    public function send(
        string $sessionId,
        string $channelId,
        array $message,
        ?string $idempotencyKey = null
    ): array {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId) . '/messages',
            'POST',
            $message,
            ['idempotency-key' => $idempotencyKey ?? Idempotency::key()]
        );
    }

    /** Channels this number follows. @return array<int,mixed> */
    public function list(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels');
    }

    /** Create a newsletter. @param array<string,mixed> $input @return array<string,mixed> */
    public function create(string $sessionId, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels', 'POST', $input);
    }

    /** Get channel metadata. @return array<string,mixed> */
    public function get(string $sessionId, string $channelId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId));
    }

    /** Mute or unmute a channel. @return array<string,mixed> */
    public function mute(string $sessionId, string $channelId, bool $muted): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId), 'PATCH', ['muted' => $muted]);
    }

    /** Unfollow a channel. @return array<string,mixed> */
    public function delete(string $sessionId, string $channelId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId), 'DELETE');
    }

    /** Follow a channel. @return array<string,mixed> */
    public function subscribe(string $sessionId, string $channelId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId) . '/subscribe', 'POST');
    }

    /** Unfollow a channel. @return array<string,mixed> */
    public function unsubscribe(string $sessionId, string $channelId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId) . '/unsubscribe', 'POST');
    }

    /** Channel info from an invite code. @return array<string,mixed> */
    public function inviteInfo(string $sessionId, string $code): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/invite/' . $this->seg($code));
    }

    /** Follow via an invite code. @return array<string,mixed> */
    public function subscribeByInvite(string $sessionId, string $code): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/invite/' . $this->seg($code) . '/subscribe', 'POST');
    }

    /** Channel message history. @param array<string,mixed> $filters @return array<int,mixed> */
    public function messages(string $sessionId, string $channelId, array $filters = []): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId) . '/messages' . $this->query($filters));
    }

    /** Reaction / view-count updates. @param array<string,mixed> $filters @return array<int,mixed> */
    public function updates(string $sessionId, string $channelId, array $filters = []): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId) . '/updates' . $this->query($filters));
    }

    /** Subscribe this connection to live updates. @return array<string,mixed> */
    public function track(string $sessionId, string $channelId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId) . '/track', 'POST');
    }

    /** React to a channel message by server id. Empty emoji removes. @return array<string,mixed> */
    public function react(string $sessionId, string $channelId, int $serverId, string $emoji): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId) . '/messages/' . $serverId . '/react', 'POST', ['emoji' => $emoji]);
    }

    /** Mark a channel message viewed. @return array<string,mixed> */
    public function markViewed(string $sessionId, string $channelId, int $serverId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/channels/' . $this->seg($channelId) . '/messages/' . $serverId . '/view', 'POST');
    }

}
