# walayer (Python)

Official Python client for the [WALayer](https://walayer.com) WhatsApp API.
Zero runtime dependencies — standard library only.

## Install

```bash
pip install walayer
```

## Send a message in three lines

```python
from walayer import WALayer

wa = WALayer(api_key="wsk_live_...")
wa.messages.send(session_id, {"type": "text", "to": "+94770000000", "body": {"text": "Hello 👋"}})
```

Every send is idempotent: the client generates an `Idempotency-Key` for you, so a
retried call never sends the same WhatsApp message twice. Pass `idempotency_key=...`
to control it.

## Resources

```python
wa.sessions.list()
wa.sessions.create(country="LK", label="Sales")

wa.messages.story(session_id, {"type": "text", "body": {"text": "Live now"}})
wa.messages.star(message_id)                    # star / pin / read / played receipts

wa.inbox.archive_chat(session_id, chat_jid)
wa.inbox.patch_chat(session_id, chat_jid, muted=True, disappearing_seconds=604800)

wa.contacts.check(session_id, ["+94770000000"]) # on-WhatsApp check
wa.contacts.block(session_id, jid)
wa.contacts.update_profile(session_id, about="Back soon")

wa.groups.create(session_id, "Sales team", participants=["+94770000000"])
wa.groups.settings(session_id, group_id, announce_only=True)
wa.groups.requests(session_id, group_id)        # join-request review

wa.communities.create(session_id, "HQ")
wa.channels.create(session_id, "Announcements")
wa.labels.create(session_id, "VIP", color=2)
wa.business.reject_call(session_id, call_id, caller_jid)

wa.webhooks.create(url="https://example.com/wa", events=["message.status", "message.inbound"])
wa.webhooks.test(webhook_id)                    # fire a signed test delivery
wa.events.types()                               # the event catalogue
wa.suppressions.add("+94770000000")             # opt someone out
```

The developer API — and this SDK — is **WhatsApp actions only**. The platform
CRM, billing, usage and cross-tenant search are dashboard features; an API key is
refused there (`403`, `detail.surface = "dashboard"`).

## Errors

Any non-2xx response raises a typed `WALayerError`:

```python
from walayer import WALayer, WALayerError

try:
    wa.messages.send(session_id, {"type": "text", "to": to, "body": {"text": text}})
except WALayerError as err:
    if err.code == "RECIPIENT_SUPPRESSED":
        ...  # the recipient opted out — do not retry
```

## Verify webhooks

Verify against the **raw** request body (re-serialized JSON breaks the HMAC):

```python
from walayer import verify_webhook

# e.g. in a Flask handler using request.get_data(as_text=True)
result = verify_webhook(
    raw_body=request.get_data(as_text=True),
    signature=request.headers.get("X-Signature"),
    timestamp=request.headers.get("X-Timestamp"),
    secret=WEBHOOK_SECRET,
)
if not result.valid:
    abort(400)
```

## Testing / custom transport

The HTTP transport is injectable, so you can unit-test without network access or
plug in `requests`/`httpx`:

```python
def fake(method, url, headers, body):
    return 202, {"x-request-id": "req_1"}, '{"data": {"id": "msg_1", "status": "queued"}}'

wa = WALayer(api_key="k", transport=fake)
```

Requires Python 3.9+. MIT licensed.

## Scope: WhatsApp actions only

This SDK covers what you can do with a linked WhatsApp number:

| Namespace | What it does |
|---|---|
| `sessions` | link status, update pacing, health, logout, delete, on-WhatsApp check |
| `messages` | send, bulk campaigns, the message log (paged), fetch one, resend |
| `inbox` | chats, chat history, mark read, session contacts, block/unblock |
| `groups` | create, roster changes, invite link, join, leave, live metadata, joined list |
| `channels` | send to a WhatsApp Channel |
| `media` | upload (base64 or URL) and a short-lived read URL |
| `webhooks` · `events` | subscribe to, and replay, what WhatsApp reported |
| `suppressions` | the opt-out list that gates sends |

It deliberately does **not** wrap the platform's own surface — billing and
plans, the CRM (contact profiles, custom fields, tags, segments, timelines),
cross-tenant search, or the dashboard's cookie-authenticated routes. Those are
WALayer product features rather than WhatsApp ones, and an SDK that mixes them
teaches an integrator that "WhatsApp" and "our account system" are the same
thing. They remain reachable over plain REST.
