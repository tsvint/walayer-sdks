import hashlib
import hmac
import unittest

from walayer import verify_webhook

SECRET = "whsec_abc"


def sign(ts, body):
    mac = hmac.new(SECRET.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
    return f"v1,sha256={mac}"


class WebhookTest(unittest.TestCase):
    def test_accepts_valid_signature(self):
        ts, body = 1_700_000_000, '{"event":"message.status"}'
        r = verify_webhook(body, sign(ts, body), ts, SECRET, now_seconds=ts + 5)
        self.assertTrue(r.valid)

    def test_rejects_tampered_body(self):
        ts = 1_700_000_000
        sig = sign(ts, '{"a":1}')
        r = verify_webhook('{"a":2}', sig, ts, SECRET, now_seconds=ts)
        self.assertFalse(r.valid)
        self.assertEqual(r.reason, "signature")

    def test_rejects_stale_timestamp(self):
        ts, body = 1_700_000_000, "{}"
        r = verify_webhook(body, sign(ts, body), ts, SECRET, now_seconds=ts + 10_000)
        self.assertEqual(r.reason, "timestamp")

    def test_rejects_malformed_header(self):
        r = verify_webhook("{}", "sha256=deadbeef", 1, SECRET, now_seconds=1)
        self.assertEqual(r.reason, "format")

    def test_accepts_string_timestamp(self):
        ts, body = 1_700_000_000, "{}"
        r = verify_webhook(body, sign(ts, body), str(ts), SECRET, now_seconds=ts)
        self.assertTrue(r.valid)

    def test_server_scheme_compatibility(self):
        # Mirror the documented server signer exactly (docs/04 §8.3): the same
        # inputs must verify — this is the cross-language guard.
        ts, body = 1_700_000_000, '{"event":"message.status","id":"evt_1","nested":{"a":[1,2,3]}}'
        server_sig = "v1,sha256=" + hmac.new(SECRET.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
        r = verify_webhook(body, server_sig, ts, SECRET, now_seconds=ts + 3)
        self.assertTrue(r.valid)


if __name__ == "__main__":
    unittest.main()
