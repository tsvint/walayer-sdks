import json
import unittest

from walayer import WALayer, WALayerError


class FakeTransport:
    """Records calls and returns a canned (status, headers, body)."""

    def __init__(self, status, payload):
        self.status = status
        self.payload = payload
        self.calls = []

    def __call__(self, method, url, headers, body):
        self.calls.append(
            {"method": method, "url": url, "headers": headers, "body": json.loads(body) if body else None}
        )
        text = "" if self.status == 204 else json.dumps(self.payload)
        return self.status, {"x-request-id": "req_1"}, text


class ClientTest(unittest.TestCase):
    def test_send_attaches_bearer_idempotency_and_unwraps_data(self):
        t = FakeTransport(202, {"data": {"id": "msg_1", "status": "queued"}})
        wa = WALayer(api_key="wsk_live_x", base_url="https://api.example.com", transport=t)

        res = wa.messages.send("sess_1", {"type": "text", "to": "+94770000000", "body": {"text": "hi"}})
        self.assertEqual(res, {"id": "msg_1", "status": "queued"})

        call = t.calls[0]
        self.assertEqual(call["method"], "POST")
        self.assertEqual(call["url"], "https://api.example.com/v1/sessions/sess_1/messages")
        self.assertEqual(call["headers"]["authorization"], "Bearer wsk_live_x")
        self.assertIn("idempotency-key", call["headers"])
        self.assertEqual(call["body"], {"type": "text", "to": "+94770000000", "body": {"text": "hi"}})

    def test_caller_idempotency_key_used_verbatim(self):
        t = FakeTransport(200, {"data": {"id": "msg_1", "status": "queued", "replay": True}})
        wa = WALayer(api_key="k", transport=t)
        wa.messages.send("sess_1", {"type": "text", "to": "+9477", "body": {"text": "hi"}}, idempotency_key="my-key")
        self.assertEqual(t.calls[0]["headers"]["idempotency-key"], "my-key")

    def test_non_2xx_raises_typed_error(self):
        t = FakeTransport(409, {"error": {"code": "RECIPIENT_SUPPRESSED", "message": "opted out"}})
        wa = WALayer(api_key="k", transport=t)
        with self.assertRaises(WALayerError) as ctx:
            wa.messages.send("sess_1", {"type": "text", "to": "+9477", "body": {"text": "hi"}})
        self.assertEqual(ctx.exception.status, 409)
        self.assertEqual(ctx.exception.code, "RECIPIENT_SUPPRESSED")
        self.assertEqual(ctx.exception.request_id, "req_1")

    def test_group_create(self):
        t = FakeTransport(201, {"data": {"id": "grp_1", "subject": "Team", "sync_state": "unsynced"}})
        wa = WALayer(api_key="k", transport=t)
        g = wa.groups.create("sess_1", "Team", participants=["+9477"])
        self.assertEqual(g["id"], "grp_1")
        self.assertEqual(t.calls[0]["body"], {"subject": "Team", "participants": ["+9477"]})

    def test_204_returns_none(self):
        t = FakeTransport(204, {})
        wa = WALayer(api_key="k", transport=t)
        self.assertIsNone(wa.webhooks.delete("wh_1"))
        self.assertEqual(t.calls[0]["method"], "DELETE")

    def test_missing_api_key_raises(self):
        with self.assertRaises(ValueError):
            WALayer(api_key="")


    def test_new_whatsapp_methods_map_to_the_right_call(self):
        t = FakeTransport(200, {"data": {}})
        wa = WALayer(api_key="k", base_url="https://api.example.com", transport=t)
        S, C, NL, COM = "sess_1", "9477@s.whatsapp.net", "1@newsletter", "1@g.us"

        wa.sessions.settings(S)
        wa.sessions.limits(S)
        wa.messages.story(S, {"type": "text", "body": {"text": "hi"}})
        wa.messages.star("msg_1", True)
        wa.messages.pin("msg_1", duration_seconds=604800)
        wa.messages.receipts("msg_1")
        wa.inbox.get_chat(S, C)
        wa.inbox.patch_chat(S, C, pinned=True)
        wa.inbox.presence(S, C, "typing")
        wa.contacts.check(S, ["+9477"])
        wa.contacts.resolve(S, [C])
        wa.contacts.blocklist(S)
        wa.contacts.update_profile(S, about="hi")
        wa.contacts.set_presence(S, "online")
        wa.groups.settings(S, "grp_1", announce_only=True)
        wa.groups.set_icon(S, "grp_1", "med_1")
        wa.groups.resolve_requests(S, "grp_1", "approve", [C])
        wa.communities.create(S, "HQ")
        wa.communities.link_group(S, COM, "2@g.us")
        wa.channels.create(S, "News")
        wa.channels.react(S, NL, 7, "\U0001F525")
        wa.labels.create(S, "VIP", color=2)
        wa.labels.associate(S, "31", chat_jid=C)
        wa.business.profile(S, C)
        wa.business.reject_call(S, "call_1", C)
        wa.business.bots(S)
        wa.media.list()
        wa.webhooks.test("wh_1")
        wa.events.types()

        urls = [c["method"] + " " + c["url"] for c in t.calls]
        def has(frag):
            return any(u.endswith(frag) for u in urls)
        self.assertTrue(has("/v1/sessions/sess_1/settings"), "settings")
        self.assertTrue(has("/v1/sessions/sess_1/stories"), "story")
        self.assertTrue(has("/v1/messages/msg_1/star"), "star")
        self.assertTrue(has("/v1/messages/msg_1/receipts"), "receipts")
        self.assertTrue(has("/v1/sessions/sess_1/contacts/check"), "contacts.check")
        self.assertTrue(has("/v1/sessions/sess_1/blocklist"), "blocklist")
        self.assertTrue(has("/v1/sessions/sess_1/profile"), "update_profile")
        self.assertTrue(has("/v1/sessions/sess_1/groups/grp_1/settings"), "group settings")
        self.assertTrue(has("/v1/sessions/sess_1/groups/grp_1/icon"), "set_icon")
        self.assertTrue(has("/v1/sessions/sess_1/communities"), "community create")
        self.assertTrue(has("/messages/7/react"), "channel react")
        self.assertTrue(has("/v1/sessions/sess_1/labels/31/associations"), "label associate")
        self.assertTrue(has("/v1/sessions/sess_1/calls/call_1/reject"), "reject_call")
        self.assertTrue(has("/v1/sessions/sess_1/bots"), "bots")
        self.assertTrue(has("/v1/media"), "media list")
        self.assertTrue(has("/v1/webhooks/wh_1/test"), "webhook test")
        self.assertTrue(has("/v1/events/types"), "event types")



if __name__ == "__main__":
    unittest.main()


class ChannelsTest(unittest.TestCase):
    def test_channel_send_targets_channel_path(self):
        t = FakeTransport(202, {"data": {"id": "msg_9", "status": "queued"}})
        wa = WALayer(api_key="k", base_url="https://api.example.com", transport=t)
        wa.channels.send("sess_1", "120363144742733222", {"type": "text", "body": {"text": "hi"}})
        call = t.calls[0]
        self.assertEqual(call["url"], "https://api.example.com/v1/sessions/sess_1/channels/120363144742733222/messages")
        self.assertIn("idempotency-key", call["headers"])
        self.assertEqual(call["body"], {"type": "text", "body": {"text": "hi"}})

class PathEscapingTest(unittest.TestCase):
    """A path value must not be able to escape its own segment.

    quote()'s default safe='/' left slashes unescaped, so "../../admin" passed
    into the path verbatim — the caller's API key attached to a rewritten
    route. JIDs arrive from inbound messages, which makes path values
    attacker-influenced by construction. The other three SDKs already escape
    everything; this pins Python to the same behaviour.
    """

    def test_traversal_cannot_add_segments(self):
        t = FakeTransport(200, {"data": {}})
        wa = WALayer(api_key="wsk_test_x", base_url="https://api.example.com", transport=t)
        wa.sessions.get("../../admin?x=1")
        url = t.calls[0]["url"]
        self.assertNotIn("/../", url)
        self.assertNotIn("?", url.split("/v1/", 1)[1], "a path value started a query string")
        self.assertIn("..%2F..%2Fadmin%3Fx%3D1", url)

