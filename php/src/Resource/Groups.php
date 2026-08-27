<?php

declare(strict_types=1);

namespace WALayer\Resource;

/**
 * Groups (docs/04 §3.4). Writes record intent and answer `202`; the runner
 * reconciles them upstream, which is why participants carry a `sync_state`.
 */
final class Groups extends AbstractResource
{
    /**
     * @param  list<string>|null   $participants
     * @return array<string,mixed>
     */
    public function create(string $sessionId, string $subject, ?array $participants = null): array
    {
        $body = ['subject' => $subject];
        if ($participants !== null) {
            $body['participants'] = $participants;
        }

        /** @var array<string,mixed> */
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups', 'POST', $body);
    }

    /** @return list<array<string,mixed>> */
    public function list(string $sessionId): array
    {
        /** @var list<array<string,mixed>> */
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups');
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $groupId): array
    {
        /** @var array<string,mixed> */
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($groupId)
        );
    }

    /**
     * @param  string              $action       add | remove | promote | demote
     * @param  list<string>        $participants
     * @return array<string,mixed> per-participant results
     */
    public function participants(string $sessionId, string $groupId, string $action, array $participants): array
    {
        /** @var array<string,mixed> */
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($groupId) . '/participants',
            'POST',
            ['action' => $action, 'participants' => $participants]
        );
    }

    /** @return array<string,mixed> */
    public function leave(string $sessionId, string $groupId): array
    {
        /** @var array<string,mixed> */
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($groupId) . '/leave',
            'POST'
        );
    }

    /**
     * The group's invite link.
     *
     * @return array<string,mixed>
     */
    public function invite(string $sessionId, string $groupId): array
    {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($groupId) . '/invite'
        );
    }

    /** @return array<string,mixed> */
    public function acceptInvite(string $sessionId, string $code): array
    {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/groups/invite/accept',
            'POST',
            ['code' => $code]
        );
    }

    /**
     * Metadata read straight from WhatsApp, with an authoritative participant
     * list — as opposed to the stored copy, which can lag (FR-181).
     *
     * @return array<string,mixed>
     */
    public function live(string $sessionId, string $groupId): array
    {
        return $this->http->request(
            '/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($groupId) . '/live'
        );
    }

    /**
     * Every group this number belongs to.
     *
     * @return array<string,mixed>
     */
    public function joined(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/joined');
    }

    /** Update subject / description. @param array<string,mixed> $input @return array<string,mixed> */
    public function update(string $sessionId, string $gid, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid), 'PATCH', $input);
    }

    /** The group icon. @return array<string,mixed> */
    public function getIcon(string $sessionId, string $gid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid) . '/icon');
    }

    /** Set the group icon from a JPEG media id. @return array<string,mixed> */
    public function setIcon(string $sessionId, string $gid, string $mediaId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid) . '/icon', 'PUT', ['media' => ['media_id' => $mediaId]]);
    }

    /** Delete the group icon. @return array<string,mixed> */
    public function deleteIcon(string $sessionId, string $gid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid) . '/icon', 'DELETE');
    }

    /** announce_only / locked / join_approval / member_add. @param array<string,mixed> $input @return array<string,mixed> */
    public function settings(string $sessionId, string $gid, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid) . '/settings', 'PATCH', $input);
    }

    /** Pending join requests. @return array<string,mixed> */
    public function requests(string $sessionId, string $gid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid) . '/requests');
    }

    /** Approve / reject join requests. @param array<int,string> $participants @return array<string,mixed> */
    public function resolveRequests(string $sessionId, string $gid, string $action, array $participants): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid) . '/requests', 'POST', ['action' => $action, 'participants' => $participants]);
    }

    /** Revoke the invite and return the replacement link. @return array<string,mixed> */
    public function revokeInvite(string $sessionId, string $gid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid) . '/invite', 'DELETE');
    }

    /** Group metadata by invite code, without joining. @return array<string,mixed> */
    public function inviteInfo(string $sessionId, string $code): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/invite/' . $this->seg($code));
    }

    /** Send the invite link as a message. @return array<string,mixed> */
    public function sendInvite(string $sessionId, string $gid, string $to, ?string $text = null, ?string $idempotencyKey = null): array
    {
        $body = ['to' => $to];
        if ($text !== null) { $body['text'] = $text; }
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/groups/' . $this->seg($gid) . '/invite/send', 'POST', $body, ['idempotency-key' => $idempotencyKey ?? \WALayer\Idempotency::generate()]);
    }

}
