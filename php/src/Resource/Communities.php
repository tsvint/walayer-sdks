<?php

declare(strict_types=1);

namespace WALayer\Resource;

/** WhatsApp Communities — parent groups with linked subgroups. */
final class Communities extends AbstractResource
{
    /** @return array<int,mixed> */
    public function list(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(string $sessionId, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities', 'POST', $input);
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $cid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(string $sessionId, string $cid, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid), 'PATCH', $input);
    }

    /** Deactivate (leave as owner). @return array<string,mixed> */
    public function deactivate(string $sessionId, string $cid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid), 'DELETE');
    }

    /** @return array<string,mixed> */
    public function subgroups(string $sessionId, string $cid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid) . '/subgroups');
    }

    /** @return array<string,mixed> */
    public function linkGroup(string $sessionId, string $cid, string $groupJid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid) . '/subgroups', 'POST', ['group_jid' => $groupJid]);
    }

    /** @return array<string,mixed> */
    public function unlinkGroup(string $sessionId, string $cid, string $gid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid) . '/subgroups/' . $this->seg($gid), 'DELETE');
    }

    /** @return array<string,mixed> */
    public function joinSubgroup(string $sessionId, string $cid, string $gid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid) . '/subgroups/' . $this->seg($gid) . '/join', 'POST');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createGroup(string $sessionId, string $cid, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid) . '/groups', 'POST', $input);
    }

    /** @return array<string,mixed> */
    public function participants(string $sessionId, string $cid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid) . '/participants');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updateParticipants(string $sessionId, string $cid, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid) . '/participants', 'POST', $input);
    }

    /** @return array<string,mixed> */
    public function revokeInvite(string $sessionId, string $cid): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/communities/' . $this->seg($cid) . '/invite', 'DELETE');
    }
}
