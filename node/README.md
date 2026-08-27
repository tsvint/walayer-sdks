# @walayer/sdk

Official Node.js client for the [WALayer](https://walayer.com) WhatsApp API.

## Install

```bash
npm install @walayer/sdk
```

## Send a message in three lines

```ts
import { WALayer } from "@walayer/sdk";

const wa = new WALayer({ apiKey: process.env.WALAYER_API_KEY! });
await wa.messages.send(sessionId, { type: "text", to: "+94770000000", body: { text: "Hello 👋" } });
```

Every send is idempotent: the SDK generates an `Idempotency-Key` for you, so a
retried call never sends the same WhatsApp message twice. Pass your own key as
the third argument when you want to control it.

## Resources

```ts
await wa.sessions.list();
await wa.sessions.create({ country: "LK", label: "Sales" });

await wa.webhooks.create({ url: "https://example.com/wa", events: ["message.status", "message.inbound"] });

await wa.suppressions.add("+94770000000");   // opt someone out
await wa.suppressions.list();

await wa.events.list({ since: 0, limit: 100 });
```

## Errors

Any non-2xx response throws a typed `WALayerError`:

```ts
import { WALayer, WALayerError } from "@walayer/sdk";

try {
  await wa.messages.send(sessionId, { type: "text", to, body: { text } });
} catch (err) {
  if (err instanceof WALayerError && err.code === "RECIPIENT_SUPPRESSED") {
    // the recipient opted out — do not retry
  }
}
```

## Verify webhooks

Verify against the **raw** request body (re-serialized JSON breaks the HMAC):

```ts
import { verifyWebhook } from "@walayer/sdk";

// e.g. in an Express handler with express.raw({ type: "application/json" })
const result = verifyWebhook({
  rawBody: req.body.toString("utf8"),
  signature: req.header("X-Signature"),
  timestamp: req.header("X-Timestamp"),
  secret: process.env.WALAYER_WEBHOOK_SECRET!,
});
if (!result.valid) return res.status(400).end();
```

## Configuration

```ts
new WALayer({
  apiKey: "wsk_live_…",          // required
  baseUrl: "https://api.walayer.com", // optional; override for self-hosted
});
```

Requires Node 18+ (uses the global `fetch`). MIT licensed.

## Scope: WhatsApp actions only

This SDK covers what you can do with a linked WhatsApp number:

| Namespace | What it does |
|---|---|
| `sessions` | link, update pacing, health, settings, limits, pair, logout, delete |
| `messages` | send (all 22 types), bulk campaigns, stories, the log (paged), receipts, resend, star / pin / read / played |
| `inbox` | chats, history, mark read, get / delete / archive / pin / mute / disappearing, chat presence |
| `contacts` | on-WhatsApp check, LID↔phone resolve, block / unblock, blocklist, about, profile, presence + subscribe, own profile & presence |
| `groups` | create, roster, invite link (get / revoke / send), join, leave, live, icon, settings, join-requests |
| `communities` | create, subgroups (link / unlink / join), participants, group-in-community, deactivate |
| `channels` | create, follow / unfollow, mute, messages, updates, track, react, mark viewed, send, invite |
| `labels` | create, rename, delete, associate a chat or message |
| `business` | business profile, order items, resolve link, reject call, list bots |
| `media` | upload (base64 or URL), list, read URL, delete |
| `webhooks` · `events` | subscribe, test, replay, and the event catalogue |
| `suppressions` | the opt-out list that gates sends |

It deliberately does **not** wrap the platform's own surface — billing and
plans, the CRM (contact profiles, custom fields, tags, segments, timelines),
cross-tenant search, or the dashboard's cookie-authenticated routes. Those are
WALayer product features rather than WhatsApp ones, and the API refuses an API
key on them (`403`, `detail.surface = "dashboard"`); the dashboard reaches them
with a session cookie.
