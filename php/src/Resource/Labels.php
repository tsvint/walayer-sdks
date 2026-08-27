<?php

declare(strict_types=1);

namespace WALayer\Resource;

/** WhatsApp Business labels — coloured, per-number, on chats & messages. */
final class Labels extends AbstractResource
{
    /** @return array<int,mixed> */
    public function list(string $sessionId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/labels');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(string $sessionId, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/labels', 'POST', $input);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(string $sessionId, string $labelId, array $input): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/labels/' . $this->seg($labelId), 'PATCH', $input);
    }

    /** @return array<string,mixed> */
    public function delete(string $sessionId, string $labelId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/labels/' . $this->seg($labelId), 'DELETE');
    }

    /** @return array<int,mixed> */
    public function associations(string $sessionId, string $labelId): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/labels/' . $this->seg($labelId) . '/associations');
    }

    /** Label a chat or a message. @param array<string,mixed> $target @return array<string,mixed> */
    public function associate(string $sessionId, string $labelId, array $target): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/labels/' . $this->seg($labelId) . '/associations', 'POST', $target);
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    public function dissociate(string $sessionId, string $labelId, array $target): array
    {
        return $this->http->request('/v1/sessions/' . $this->seg($sessionId) . '/labels/' . $this->seg($labelId) . '/associations', 'DELETE', $target);
    }
}
