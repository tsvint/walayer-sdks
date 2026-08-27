# WALayer SDKs

Official clients for the [WALayer](https://walayer.com) WhatsApp API — link your own WhatsApp
number and send, receive and manage it over REST.

| Language | Package | Install |
|---|---|---|
| Node / TypeScript | `@walayer/sdk` | `npm i @walayer/sdk` |
| Python | `walayer` | `pip install walayer` |
| PHP | `walayer/walayer-php` | `composer require walayer/walayer-php` |
| Go | `github.com/tsvint/walayer-sdks/go` | `go get github.com/tsvint/walayer-sdks/go` |

Every one of them has **zero runtime dependencies**. They are thin JSON clients; pulling an HTTP
or JSON library into your build to save a few hundred lines is a bad trade.

## Why this repository is separate

The SDKs live here rather than in the product monorepo because **publishing requires a public
repository**. Go resolves a module path by fetching the repository at that URL, and Packagist
fetches PHP packages from their VCS source. Neither works from a private repo.

Keeping them here also means the module path is the repository URL, so no vanity host
(`go.walayer.com` or similar) has to exist or be maintained.

## The same shape in four languages

All four expose the same resource groups — `sessions`, `messages`, `inbox`, `media`, `channels`,
`webhooks`, `suppressions` — with the same method names in each language's casing. A team running
two of them should be able to read one and predict the other.

Two behaviours are identical everywhere, and both exist to prevent the same failure:

**Idempotency keys are required on sends, not optional.** The API requires one, and it is what
stops a network retry from delivering the same WhatsApp message to a real person twice. Derive it
from your own domain — an order id, not a UUID generated per attempt, which defeats the point.

**A send whose outcome is unknown is never reported as safe to retry.** If a request times out you
do not know whether it landed. Every client treats that as unknown rather than failed.

## Verifying webhooks

Each SDK ships a verifier. The scheme is the same in all of them:

```
X-Signature: v1,sha256=<hex>
hex = HMAC-SHA256(secret, "{timestamp}.{rawBody}")
```

Verify against the **raw bytes**, exactly as received. Re-serialized JSON breaks the MAC because
key order and whitespace are part of what was signed — this is the single most common way webhook
verification is got wrong. Signatures are compared in constant time, with a 300-second replay
window in both directions.

## Layout

```
go/       github.com/tsvint/walayer-sdks/go
node/     @walayer/sdk
php/      walayer/walayer-php
python/   walayer
```

Each directory is a self-contained package with its own README, tests and licence.

## Tests

```bash
(cd go     && go test -race ./...)
(cd node   && npm ci && npm test)
(cd python && python -m unittest discover -s tests)
(cd php    && composer install && php tests/run.php)
```

## Releasing

Versions are per package. Go is released by tagging; the others publish to their registry.

```bash
# Go — the tag must be prefixed with the subdirectory
git tag go/v0.1.0 && git push origin go/v0.1.0

# npm
(cd node && npm publish)          # prepublishOnly runs the build

# PyPI
(cd python && python -m build && twine upload dist/*)

# Packagist — submit the repository URL once, then it tracks tags
git tag php/v0.1.0 && git push origin php/v0.1.0
```

If the repository ever moves, run `./set-repo.sh <owner> [repo]` before tagging. The Go module
path has to equal the repository URL, and a mismatch is not a warning — `go get` simply fails.

## Documentation

Full REST reference: [walayer.com/docs](https://walayer.com/docs).
