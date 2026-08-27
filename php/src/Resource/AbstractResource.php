<?php

declare(strict_types=1);

namespace WALayer\Resource;

use WALayer\Http;

/**
 * Shared plumbing for the resource namespaces: the transport handle and safe
 * path-segment encoding (ids and phone numbers go into the URL, and `+94770…`
 * must not arrive as a space).
 */
abstract class AbstractResource
{
    public function __construct(protected readonly Http $http)
    {
    }

    protected function seg(string $value): string
    {
        return \rawurlencode($value);
    }

    /**
     * @param  array<string,mixed> $params
     * @return string              a leading-`?` query string, or '' when nothing is set
     */
    protected function query(array $params): string
    {
        $filtered = \array_filter($params, static fn ($v): bool => $v !== null);

        return $filtered === [] ? '' : '?' . \http_build_query($filtered);
    }
}
