# WALayer Go SDK

The Go client for the [WALayer](https://walayer.com) WhatsApp API — link your own
WhatsApp number and send, receive, and manage it over REST.

Zero dependencies outside the standard library.

## Install

```bash
go get github.com/tsvint/walayer-sdks/go
```

## Send a message

```go
package main

import (
	"context"
	"log"

	walayer "github.com/tsvint/walayer-sdks/go"
)

func main() {
	client := walayer.New("wal_live_...")

	res, err := client.Messages.Send(context.Background(), "sess_01j...", walayer.M{
		"type": "text",
		"to":   "+94771234567",
		"body": "Your order #4417 has shipped.",
	}, "order-4417-shipped")
	if err != nil {
		log.Fatal(err)
	}

	log.Printf("queued as %v", res["id"])
}
```

The last argument is the **idempotency key**, and it is required. It is what stops a network
retry from sending the same WhatsApp message to a real person twice, so derive it from your own
domain — an order id, not a UUID generated per attempt, which would defeat the point.

## Handling errors

Branch on the code, never the message. The message is written for a human and will be reworded;
the code is a contract.

```go
_, err := client.Messages.Send(ctx, sessionID, msg, key)

switch walayer.CodeOf(err) {
case "":
	// success
case "RECIPIENT_SUPPRESSED":
	// they opted out. Terminal — do not retry, do not "try once more".
case "SESSION_SUSPENDED":
	// the number is disconnected for billing.
default:
	if walayer.Retryable(err) {
		// 429 or 5xx on a READ. See the note below before retrying a send.
	}
}
```

`Retryable` is deliberately conservative and returns `false` for a transport failure with no
response. If a send times out you do not know whether it landed, and retrying blind is worse than
the original failure — the status is `unknown` and the reconciler decides.

## Verifying webhooks

```go
func handler(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(r.Body)
	if err != nil {
		w.WriteHeader(http.StatusBadRequest)
		return
	}

	if !walayer.VerifyRequest(r, body, os.Getenv("WALAYER_WEBHOOK_SECRET")).Valid {
		w.WriteHeader(http.StatusBadRequest)
		return
	}

	// Safe to parse now.
}
```

Verify against the **raw bytes**, exactly as received. Re-serialized JSON breaks the HMAC, because
key order and whitespace are part of what was signed — this is the single most common way webhook
verification is got wrong.

Signatures are checked in constant time, with a 300-second replay window in both directions.

## Configuration

```go
client := walayer.New(
	os.Getenv("WALAYER_API_KEY"),
	walayer.WithBaseURL("https://api.walayer.com"),
	walayer.WithHTTPClient(&http.Client{Timeout: 10 * time.Second}),
)
```

`WithHTTPClient` takes anything with a `Do(*http.Request) (*http.Response, error)` method, so
tests need no network.

## Resources

`Sessions`, `Messages`, `Inbox`, `Media`, `Channels`, `Webhooks`, `Suppressions` — the same groups
and method names as the TypeScript, Python and PHP SDKs, in Go casing. A team running two of them
can read either.

Bodies and responses are `walayer.M` (`map[string]any`) rather than generated structs. The API
surface is wide and still moving, and a struct per message type would be stale within a release.
Define your own types and unmarshal into them where you want them.

Full REST reference: [walayer.com/docs](https://walayer.com/docs).

## Tests

```bash
go test -race ./...
```
