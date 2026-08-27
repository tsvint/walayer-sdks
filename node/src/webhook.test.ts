import { test } from "node:test";
import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { verifyWebhook } from "./webhook.js";

const SECRET = "whsec_abc";
const sign = (ts: number, body: string) => `v1,sha256=${createHmac("sha256", SECRET).update(`${ts}.${body}`).digest("hex")}`;

test("accepts a valid signature within the window", () => {
  const ts = 1_700_000_000;
  const body = JSON.stringify({ event: "message.status" });
  const r = verifyWebhook({ rawBody: body, signature: sign(ts, body), timestamp: ts, secret: SECRET, nowSeconds: ts + 5 });
  assert.deepEqual(r, { valid: true });
});

test("rejects a tampered body", () => {
  const ts = 1_700_000_000;
  const sig = sign(ts, JSON.stringify({ a: 1 }));
  const r = verifyWebhook({ rawBody: JSON.stringify({ a: 2 }), signature: sig, timestamp: ts, secret: SECRET, nowSeconds: ts });
  assert.equal(r.valid, false);
  assert.equal(r.reason, "signature");
});

test("rejects a stale timestamp", () => {
  const ts = 1_700_000_000;
  const body = "{}";
  const r = verifyWebhook({ rawBody: body, signature: sign(ts, body), timestamp: ts, secret: SECRET, nowSeconds: ts + 10_000 });
  assert.equal(r.reason, "timestamp");
});

test("rejects a malformed header", () => {
  const r = verifyWebhook({ rawBody: "{}", signature: "sha256=deadbeef", timestamp: 1, secret: SECRET, nowSeconds: 1 });
  assert.equal(r.reason, "format");
});

test("accepts a string timestamp header", () => {
  const ts = 1_700_000_000;
  const body = "{}";
  const r = verifyWebhook({ rawBody: body, signature: sign(ts, body), timestamp: String(ts), secret: SECRET, nowSeconds: ts });
  assert.ok(r.valid);
});
