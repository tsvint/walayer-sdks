"""The WALayer client and its resource namespaces."""
from __future__ import annotations

import uuid
from urllib.parse import quote
from typing import Any, Dict, List, Optional

from .http import Http, Transport


class WALayer:
    """WALayer API client. Three lines to send:

    >>> from walayer import WALayer
    >>> wa = WALayer(api_key="wsk_live_...")
    >>> wa.messages.send(session_id, {"type": "text", "to": "+9477...", "body": {"text": "hi"}})
    """

    def __init__(self, api_key: str, base_url: str = "https://api.walayer.com", transport: Optional[Transport] = None):
        http = Http(api_key, base_url, transport)
        self.sessions = Sessions(http)
        self.messages = Messages(http)
        self.inbox = Inbox(http)
        self.media = Media(http)
        self.webhooks = Webhooks(http)
        self.channels = Channels(http)
        self.suppressions = Suppressions(http)
        self.groups = Groups(http)
        self.events = Events(http)
        self.contacts = Contacts(http)
        self.communities = Communities(http)
        self.labels = Labels(http)
        self.business = Business(http)


class Sessions:
    def __init__(self, http: Http):
        self._http = http

    def list(self) -> List[Dict[str, Any]]:
        return self._http.request("/v1/sessions")

    def get(self, session_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{session_id}")

    def update(self, session_id: str, **fields: Any) -> Dict[str, Any]:
        """Partial update. ``pacing`` is MERGED, not replaced (docs/04 §4.4)."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}", method="PATCH", body=fields)

    def delete(self, session_id: str) -> None:
        """Graceful logout, credential shred, proxy release."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}", method="DELETE")

    def logout(self, session_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/logout", method="POST")

    def health(self, session_id: str) -> Dict[str, Any]:
        """Trust score, warmup stage and delivery stats (FR-033/262)."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/health")

    def on_whatsapp(self, session_id: str, phones: List[str]) -> Dict[str, Any]:
        """
        Is each number registered on WhatsApp?

        Capped at 50 numbers per call — this is the endpoint that would
        otherwise turn the platform into a number-validity oracle (docs/04 §9).
        """
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/on-whatsapp", method="POST", body={"phones": phones}
        )

    def create(self, country: str, label: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"country": country}
        if label is not None:
            body["label"] = label
        return self._http.request("/v1/sessions", method="POST", body=body)

    def settings(self, session_id: str) -> Dict[str, Any]:
        """The settable half of the session (label, pacing, caps, warmup)."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/settings")

    def reset_settings(self, session_id: str) -> Dict[str, Any]:
        """Reset settings to plan defaults. Warmup stage never resets up."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/settings/reset", method="POST")

    def limits(self, session_id: str) -> Dict[str, Any]:
        """Current send caps & warmup limits."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/limits")

    def pair(self, session_id: str, method: Optional[str] = None, phone_e164: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {}
        if method is not None:
            body["method"] = method
        if phone_e164 is not None:
            body["phone_e164"] = phone_e164
        return self._http.request(f"/v1/sessions/{quote(session_id)}/pair", method="POST", body=body)



class Messages:
    def __init__(self, http: Http):
        self._http = http

    def send(self, session_id: str, message: Dict[str, Any], idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        """Send a message. An Idempotency-Key is generated when omitted, so a
        retried call never sends twice (invariant I4)."""
        key = idempotency_key or str(uuid.uuid4())
        return self._http.request(
            f"/v1/sessions/{session_id}/messages",
            method="POST",
            body=message,
            extra_headers={"idempotency-key": key},
        )

    def bulk(self, session_id: str, template: Dict[str, Any], recipients: List[Dict[str, Any]],
             name: Optional[str] = None, idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        """A paced campaign. Suppressed recipients are dropped server-side."""
        body: Dict[str, Any] = {"template": template, "recipients": recipients}
        if name:
            body["name"] = name
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/messages/bulk",
            method="POST",
            body=body,
            extra_headers={"idempotency-key": idempotency_key or str(uuid.uuid4())},
        )

    def list(self, **params: Any) -> Dict[str, Any]:
        """
        The message log. Returns the WHOLE envelope, so ``next_cursor`` survives
        — an SDK that hands back only the rows cannot page.

        Accepts session, status, direction, type, q, from, to, limit, cursor.
        """
        return self._http.request("/v1/messages", params=params, envelope=True)

    def get(self, message_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/messages/{quote(message_id)}")

    def resend(self, message_id: str) -> Dict[str, Any]:
        """Resend an undelivered message. A delivered one is refused (§7)."""
        return self._http.request(f"/v1/messages/{quote(message_id)}/resend", method="POST")

    def receipts(self, message_id: str) -> Dict[str, Any]:
        """Delivery / read receipts for a message."""
        return self._http.request(f"/v1/messages/{quote(message_id)}/receipts")

    def story(self, session_id: str, story: Dict[str, Any], idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        """Publish a status/story. `story` = {"type": ..., "body": {...}, "options": {...}}."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/stories",
            method="POST",
            body=story,
            extra_headers={"idempotency-key": idempotency_key or str(uuid.uuid4())},
        )

    def star(self, message_id: str, starred: bool = True) -> Dict[str, Any]:
        return self._http.request(f"/v1/messages/{quote(message_id)}/star", method="POST", body={"starred": starred})

    def pin(self, message_id: str, pinned: bool = True, duration_seconds: Optional[int] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"pinned": pinned}
        if duration_seconds is not None:
            body["duration_seconds"] = duration_seconds
        return self._http.request(f"/v1/messages/{quote(message_id)}/pin", method="POST", body=body)

    def mark_read(self, message_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/messages/{quote(message_id)}/read", method="POST")

    def mark_played(self, message_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/messages/{quote(message_id)}/played", method="POST")


class Inbox:
    """WhatsApp conversations on a linked number."""

    def __init__(self, http: Http):
        self._http = http

    def chats(self, session_id: str, q: Optional[str] = None, unread: Optional[bool] = None,
              kind: Optional[str] = None, limit: Optional[int] = None) -> List[Dict[str, Any]]:
        """Conversations, newest first. ``q`` matches name, group subject or number."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/chats",
            params={"q": q, "unread": unread, "kind": kind, "limit": limit},
        )

    def messages(self, session_id: str, chat_jid: str, q: Optional[str] = None,
                 limit: Optional[int] = None) -> List[Dict[str, Any]]:
        """One conversation, oldest first. ``q`` searches within it."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/chats/{quote(chat_jid)}/messages",
            params={"q": q, "limit": limit},
        )

    def mark_read(self, session_id: str, chat_jid: str) -> None:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/chats/{quote(chat_jid)}/read", method="POST"
        )

    def contacts(self, session_id: str) -> List[Dict[str, Any]]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts")

    def upsert_contact(self, session_id: str, jid: str, **fields: Any) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}", method="PUT", body=fields
        )

    def block(self, session_id: str, jid: str) -> Dict[str, Any]:
        """Records the intent; ``202`` means recorded, not performed (docs/04 §3.3)."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}/block", method="POST"
        )

    def unblock(self, session_id: str, jid: str) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}/unblock", method="POST"
        )

    def get_chat(self, session_id: str, chat_jid: str) -> Dict[str, Any]:
        """One chat with its state (archived, pinned, muted, disappearing timer)."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/chats/{quote(chat_jid)}")

    def delete_chat(self, session_id: str, chat_jid: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/chats/{quote(chat_jid)}", method="DELETE")

    def archive_chat(self, session_id: str, chat_jid: str, archived: bool = True) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/chats/{quote(chat_jid)}/archive",
            method="POST", body={"archived": archived},
        )

    def patch_chat(self, session_id: str, chat_jid: str, **fields: Any) -> Dict[str, Any]:
        """Pin / mute / unread / disappearing timer. Only set keys change."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/chats/{quote(chat_jid)}", method="PATCH", body=fields)

    def presence(self, session_id: str, chat_jid: str, state: str) -> Dict[str, Any]:
        """Send typing / recording / paused presence into a chat."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/chats/{quote(chat_jid)}/presence",
            method="POST", body={"state": state},
        )


class Media:
    def __init__(self, http: Http):
        self._http = http

    def upload(self, **fields: Any) -> Dict[str, Any]:
        """
        Upload by ``data_base64`` or by ``url``. The URL path is SSRF-guarded
        server-side, so a customer-supplied address cannot reach private ranges.
        """
        return self._http.request("/v1/media", method="POST", body=fields)

    def get(self, media_id: str) -> Dict[str, Any]:
        """A short-lived read URL. The object key itself is never returned."""
        return self._http.request(f"/v1/media/{quote(media_id)}")

    def list(self, **params: Any) -> List[Dict[str, Any]]:
        """The media library (metadata only)."""
        return self._http.request("/v1/media", params=params)

    def delete(self, media_id: str) -> None:
        self._http.request(f"/v1/media/{quote(media_id)}", method="DELETE")


class Channels:
    def __init__(self, http: Http):
        self._http = http

    def list(self, session_id: str) -> List[Dict[str, Any]]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels")

    def create(self, session_id: str, name: str, description: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"name": name}
        if description is not None:
            body["description"] = description
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels", method="POST", body=body)

    def get(self, session_id: str, channel_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}")

    def mute(self, session_id: str, channel_id: str, muted: bool) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}", method="PATCH", body={"muted": muted})

    def delete(self, session_id: str, channel_id: str) -> Dict[str, Any]:
        """Unfollow a channel."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}", method="DELETE")

    def subscribe(self, session_id: str, channel_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}/subscribe", method="POST")

    def unsubscribe(self, session_id: str, channel_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}/unsubscribe", method="POST")

    def invite_info(self, session_id: str, code: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/invite/{quote(code)}")

    def subscribe_by_invite(self, session_id: str, code: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/invite/{quote(code)}/subscribe", method="POST")

    def messages(self, session_id: str, channel_id: str, **params: Any) -> List[Dict[str, Any]]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}/messages", params=params)

    def updates(self, session_id: str, channel_id: str, **params: Any) -> List[Dict[str, Any]]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}/updates", params=params)

    def track(self, session_id: str, channel_id: str) -> Dict[str, Any]:
        """Subscribe this connection to live reaction/view updates."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}/track", method="POST")

    def react(self, session_id: str, channel_id: str, server_id: int, emoji: str) -> Dict[str, Any]:
        """React to a channel message by its numeric server id. Empty emoji removes."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}/messages/{server_id}/react",
            method="POST", body={"emoji": emoji},
        )

    def mark_viewed(self, session_id: str, channel_id: str, server_id: int) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}/messages/{server_id}/view",
            method="POST",
        )

    def send_invite(self, session_id: str, channel_id: str, to: str, text: Optional[str] = None,
                    idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"to": to}
        if text is not None:
            body["text"] = text
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/channels/{quote(channel_id)}/invite/send",
            method="POST", body=body,
            extra_headers={"idempotency-key": idempotency_key or str(uuid.uuid4())},
        )

    def send(self, session_id: str, channel_id: str, message: Dict[str, Any], idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        """Send to a channel/newsletter. The channel id is the target - no `to` in the body."""
        key = idempotency_key or str(uuid.uuid4())
        return self._http.request(
            f"/v1/sessions/{session_id}/channels/{channel_id}/messages",
            method="POST",
            body=message,
            extra_headers={"idempotency-key": key},
        )


class Webhooks:
    def __init__(self, http: Http):
        self._http = http

    def list(self) -> List[Dict[str, Any]]:
        return self._http.request("/v1/webhooks")

    def create(self, url: str, events: List[str], session_id: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"url": url, "events": events}
        if session_id is not None:
            body["session_id"] = session_id
        return self._http.request("/v1/webhooks", method="POST", body=body)

    def update(self, webhook_id: str, **fields: Any) -> Dict[str, Any]:
        return self._http.request(f"/v1/webhooks/{quote(webhook_id)}", method="PATCH", body=fields)

    def delete(self, webhook_id: str) -> None:
        self._http.request(f"/v1/webhooks/{webhook_id}", method="DELETE")

    def test(self, webhook_id: str) -> Dict[str, Any]:
        """Fire a signed test delivery at the endpoint now."""
        return self._http.request(f"/v1/webhooks/{quote(webhook_id)}/test", method="POST")


class Suppressions:
    def __init__(self, http: Http):
        self._http = http

    def list(self) -> List[Dict[str, Any]]:
        return self._http.request("/v1/suppressions")

    def add(self, phone: str, reason: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"phone": phone}
        if reason is not None:
            body["reason"] = reason
        return self._http.request("/v1/suppressions", method="POST", body=body)

    def remove(self, phone: str) -> None:
        self._http.request(f"/v1/suppressions/{phone}", method="DELETE")


class Groups:
    def __init__(self, http: Http):
        self._http = http

    def create(self, session_id: str, subject: str, participants: Optional[List[str]] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"subject": subject}
        if participants is not None:
            body["participants"] = participants
        return self._http.request(f"/v1/sessions/{session_id}/groups", method="POST", body=body)

    def list(self, session_id: str) -> List[Dict[str, Any]]:
        return self._http.request(f"/v1/sessions/{session_id}/groups")

    def get(self, session_id: str, group_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{session_id}/groups/{group_id}")

    def participants(self, session_id: str, group_id: str, action: str, participants: List[str]) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{session_id}/groups/{group_id}/participants",
            method="POST",
            body={"action": action, "participants": participants},
        )

    def leave(self, session_id: str, group_id: str) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/leave", method="POST"
        )

    def invite(self, session_id: str, group_id: str) -> Dict[str, Any]:
        """The group's invite link."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/invite"
        )

    def accept_invite(self, session_id: str, code: str) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/groups/invite/accept", method="POST", body={"code": code}
        )

    def live(self, session_id: str, group_id: str) -> Dict[str, Any]:
        """Metadata straight from WhatsApp, with an authoritative roster (FR-181)."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/live"
        )

    def joined(self, session_id: str) -> Dict[str, Any]:
        """Every group this number belongs to."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/groups/joined")

    def update(self, session_id: str, group_id: str, **fields: Any) -> Dict[str, Any]:
        """Update subject and/or description."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}", method="PATCH", body=fields)

    def get_icon(self, session_id: str, group_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/icon")

    def set_icon(self, session_id: str, group_id: str, media_id: str) -> Dict[str, Any]:
        """Set the group icon from a JPEG media id."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/icon",
            method="PUT", body={"media": {"media_id": media_id}},
        )

    def delete_icon(self, session_id: str, group_id: str) -> None:
        self._http.request(f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/icon", method="DELETE")

    def settings(self, session_id: str, group_id: str, **fields: Any) -> Dict[str, Any]:
        """announce_only / locked / join_approval (bools) and member_add mode."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/settings", method="PATCH", body=fields)

    def requests(self, session_id: str, group_id: str) -> Dict[str, Any]:
        """Pending join requests."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/requests")

    def resolve_requests(self, session_id: str, group_id: str, action: str, participants: List[str]) -> Dict[str, Any]:
        """Approve or reject pending join requests, per participant."""
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/requests",
            method="POST", body={"action": action, "participants": participants},
        )

    def revoke_invite(self, session_id: str, group_id: str) -> Dict[str, Any]:
        """Revoke the invite and return the replacement link."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/invite", method="DELETE")

    def invite_info(self, session_id: str, code: str) -> Dict[str, Any]:
        """Group metadata from an invite code, without joining."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/groups/invite/{quote(code)}")

    def send_invite(self, session_id: str, group_id: str, to: str, text: Optional[str] = None,
                    idempotency_key: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"to": to}
        if text is not None:
            body["text"] = text
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/groups/{quote(group_id)}/invite/send",
            method="POST", body=body,
            extra_headers={"idempotency-key": idempotency_key or str(uuid.uuid4())},
        )


class Events:
    def __init__(self, http: Http):
        self._http = http

    def list(self, since: Optional[int] = None, limit: Optional[int] = None) -> List[Dict[str, Any]]:
        params = []
        if since is not None:
            params.append(f"since={since}")
        if limit is not None:
            params.append(f"limit={limit}")
        qs = ("?" + "&".join(params)) if params else ""
        return self._http.request(f"/v1/events{qs}")

    def types(self) -> List[str]:
        """The event catalogue — every type a webhook may carry."""
        return self._http.request("/v1/events/types")

    def redeliver(self, event_id: str) -> Dict[str, Any]:
        """Re-deliver one event. Your handler must be idempotent on X-Delivery-Id."""
        return self._http.request(f"/v1/events/{quote(event_id)}/redeliver", method="POST")


class Contacts:
    """WhatsApp identity, presence and contact checks — distinct from the CRM,
    which is not part of the developer API. These act on the linked number and
    its WhatsApp contacts."""

    def __init__(self, http: Http):
        self._http = http

    def check(self, session_id: str, phones: List[str]) -> Dict[str, Any]:
        """Are these numbers on WhatsApp? Capped at 50 per call."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts/check", method="POST", body={"phones": phones})

    def resolve(self, session_id: str, jids: List[str]) -> Dict[str, Any]:
        """Resolve LID <-> phone JID, per entry."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts/resolve", method="POST", body={"jids": jids})

    def block(self, session_id: str, jid: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}/block", method="POST")

    def unblock(self, session_id: str, jid: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}/unblock", method="POST")

    def blocklist(self, session_id: str) -> Dict[str, Any]:
        """Recorded + live blocklist."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/blocklist")

    def about(self, session_id: str, jid: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}/about")

    def profile(self, session_id: str, jid: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}/profile")

    def presence(self, session_id: str, jid: str) -> Dict[str, Any]:
        """Last observed presence (subscribe first)."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}/presence")

    def subscribe_presence(self, session_id: str, jid: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/contacts/{quote(jid)}/presence/subscribe", method="POST")

    def own_profile(self, session_id: str) -> Dict[str, Any]:
        """The linked number's OWN profile."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/profile")

    def update_profile(self, session_id: str, **fields: Any) -> Dict[str, Any]:
        """Update the linked number's push name and/or about text."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/profile", method="PATCH", body=fields)

    def set_presence(self, session_id: str, state: str) -> Dict[str, Any]:
        """Set the linked number's own online / offline presence."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/presence", method="POST", body={"state": state})


class Communities:
    """WhatsApp Communities — parent groups with linked subgroups."""

    def __init__(self, http: Http):
        self._http = http

    def list(self, session_id: str) -> List[Dict[str, Any]]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities")

    def create(self, session_id: str, name: str, description: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"name": name}
        if description is not None:
            body["description"] = description
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities", method="POST", body=body)

    def get(self, session_id: str, community_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}")

    def update(self, session_id: str, community_id: str, **fields: Any) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}", method="PATCH", body=fields)

    def deactivate(self, session_id: str, community_id: str) -> Dict[str, Any]:
        """Deactivate (leave as owner)."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}", method="DELETE")

    def subgroups(self, session_id: str, community_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}/subgroups")

    def link_group(self, session_id: str, community_id: str, group_jid: str) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}/subgroups",
            method="POST", body={"group_jid": group_jid},
        )

    def unlink_group(self, session_id: str, community_id: str, group_id: str) -> None:
        self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}/subgroups/{quote(group_id)}", method="DELETE")

    def join_subgroup(self, session_id: str, community_id: str, group_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}/subgroups/{quote(group_id)}/join", method="POST")

    def create_group(self, session_id: str, community_id: str, subject: str, participants: Optional[List[str]] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"subject": subject}
        if participants is not None:
            body["participants"] = participants
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}/groups", method="POST", body=body)

    def participants(self, session_id: str, community_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}/participants")

    def update_participants(self, session_id: str, community_id: str, action: str, participants: List[str]) -> Dict[str, Any]:
        return self._http.request(
            f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}/participants",
            method="POST", body={"action": action, "participants": participants},
        )

    def revoke_invite(self, session_id: str, community_id: str) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/communities/{quote(community_id)}/invite", method="DELETE")


class Labels:
    """WhatsApp Business labels — coloured, per-number, on chats & messages."""

    def __init__(self, http: Http):
        self._http = http

    def list(self, session_id: str) -> List[Dict[str, Any]]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/labels")

    def create(self, session_id: str, name: str, color: int = 0, id: Optional[str] = None) -> Dict[str, Any]:
        body: Dict[str, Any] = {"name": name, "color": color}
        if id is not None:
            body["id"] = id
        return self._http.request(f"/v1/sessions/{quote(session_id)}/labels", method="POST", body=body)

    def update(self, session_id: str, label_id: str, **fields: Any) -> Dict[str, Any]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/labels/{quote(label_id)}", method="PATCH", body=fields)

    def delete(self, session_id: str, label_id: str) -> None:
        self._http.request(f"/v1/sessions/{quote(session_id)}/labels/{quote(label_id)}", method="DELETE")

    def associations(self, session_id: str, label_id: str) -> List[Dict[str, Any]]:
        return self._http.request(f"/v1/sessions/{quote(session_id)}/labels/{quote(label_id)}/associations")

    def associate(self, session_id: str, label_id: str, chat_jid: str, wa_msg_id: Optional[str] = None) -> Dict[str, Any]:
        """Label a chat, or a specific message within a chat."""
        body: Dict[str, Any] = {"chat_jid": chat_jid}
        if wa_msg_id is not None:
            body["wa_msg_id"] = wa_msg_id
        return self._http.request(f"/v1/sessions/{quote(session_id)}/labels/{quote(label_id)}/associations", method="POST", body=body)

    def dissociate(self, session_id: str, label_id: str, chat_jid: str, wa_msg_id: Optional[str] = None) -> None:
        body: Dict[str, Any] = {"chat_jid": chat_jid}
        if wa_msg_id is not None:
            body["wa_msg_id"] = wa_msg_id
        self._http.request(f"/v1/sessions/{quote(session_id)}/labels/{quote(label_id)}/associations", method="DELETE", body=body)


class Business:
    """Business reads, calls and bots."""

    def __init__(self, http: Http):
        self._http = http

    def profile(self, session_id: str, jid: Optional[str] = None) -> Dict[str, Any]:
        """A business profile — the linked number's own, or `jid`."""
        params = {"jid": jid} if jid is not None else {}
        return self._http.request(f"/v1/sessions/{quote(session_id)}/business/profile", params=params)

    def order(self, session_id: str, order_id: str, token: str) -> Dict[str, Any]:
        """Items of an order message. `token` comes from that message."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/business/orders/{quote(order_id)}", params={"token": token})

    def resolve_link(self, session_id: str, code: str) -> Dict[str, Any]:
        """Resolve a wa.me/message business link."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/business/link/{quote(code)}")

    def reject_call(self, session_id: str, call_id: str, caller_jid: str) -> Dict[str, Any]:
        """Reject an incoming call. The id and caller come from the call.received event."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/calls/{quote(call_id)}/reject", method="POST", body={"caller_jid": caller_jid})

    def bots(self, session_id: str) -> List[Dict[str, Any]]:
        """Meta AI bots visible to this number."""
        return self._http.request(f"/v1/sessions/{quote(session_id)}/bots")
