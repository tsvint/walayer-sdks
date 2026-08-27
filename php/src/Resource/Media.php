<?php

declare(strict_types=1);

namespace WALayer\Resource;

/**
 * Media for outbound messages. Bytes are stored once and referenced by id, so
 * the same file sent to a hundred recipients is uploaded once.
 */
final class Media extends AbstractResource
{
    /**
     * Upload by base64 or by URL.
     *
     * The URL path is SSRF-guarded on the server: a customer-supplied address
     * cannot be used to reach private ranges or cloud metadata.
     *
     * @param  array<string,mixed> $input session_id, filename, mime_type, and
     *                                    exactly one of data_base64 or url
     * @return array<string,mixed>
     */
    public function upload(array $input): array
    {
        return $this->http->request('/v1/media', 'POST', $input);
    }

    /**
     * A short-lived read URL. The object key itself is never returned — a raw
     * key is a worse thing to leak than a URL that expires.
     *
     * @return array<string,mixed>
     */
    public function get(string $mediaId): array
    {
        return $this->http->request('/v1/media/' . $this->seg($mediaId));
    }

    /** The media library (metadata only). @param array<string,mixed> $filters @return array<string,mixed> */
    public function list(array $filters = []): array
    {
        return $this->http->request('/v1/media' . $this->query($filters));
    }

    /** Delete media. @return array<string,mixed> */
    public function delete(string $id): array
    {
        return $this->http->request('/v1/media/' . $this->seg($id), 'DELETE');
    }

}
