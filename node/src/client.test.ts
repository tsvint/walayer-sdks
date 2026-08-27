import { test } from "node:test";
import assert from "node:assert/strict";
import { WALayer, WALayerError } from "./index.js";

interface Captured {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: unknown;
}

/** A fetch stub that records the call and returns a canned envelope. */
function stub(status: number, payload: unknown): { fetch: typeof fetch; calls: Captured[] } {
  const calls: Captured[] = [];
  const fetchImpl = (async (url: string, init: RequestInit = {}) => {
    const headers = Object.fromEntries(new Headers(init.headers).entries());
    calls.push({
      url,
      method: init.method ?? "GET",
      headers,
      body: init.body ? JSON.parse(init.body as string) : undefined,
    });
    // 204/205/304 must not carry a body per the Fetch spec.
    const noBody = status === 204 || status === 205 || status === 304;
    return new Response(noBody ? null : JSON.stringify(payload), { status, headers: { "x-request-id": "req_1" } });
  }) as unknown as typeof fetch;
  return { fetch: fetchImpl, calls };
}

test("send attaches the bearer key, an idempotency key, and unwraps data", async () => {
  const { fetch, calls } = stub(202, { data: { id: "msg_1", status: "queued" } });
  const wa = new WALayer({ apiKey: "wsk_live_x", baseUrl: "https://api.example.com", fetch });

  const res = await wa.messages.send("sess_1", { type: "text", to: "+94770000000", body: { text: "hi" } });
  assert.deepEqual(res, { id: "msg_1", status: "queued" });

  const call = calls[0]!;
  assert.equal(call.url, "https://api.example.com/v1/sessions/sess_1/messages");
  assert.equal(call.method, "POST");
  assert.equal(call.headers["authorization"], "Bearer wsk_live_x");
  assert.ok(call.headers["idempotency-key"], "auto-generated idempotency key present");
  assert.deepEqual(call.body, { type: "text", to: "+94770000000", body: { text: "hi" } });
});

test("a caller-supplied idempotency key is used verbatim", async () => {
  const { fetch, calls } = stub(200, { data: { id: "msg_1", status: "queued", replay: true } });
  const wa = new WALayer({ apiKey: "k", fetch });
  await wa.messages.send("sess_1", { type: "text", to: "+9477", body: { text: "hi" } }, "my-key-123");
  assert.equal(calls[0]!.headers["idempotency-key"], "my-key-123");
});

test("non-2xx throws a typed WALayerError with code + request id", async () => {
  const { fetch } = stub(409, { error: { code: "RECIPIENT_SUPPRESSED", message: "opted out" } });
  const wa = new WALayer({ apiKey: "k", fetch });
  await assert.rejects(
    () => wa.messages.send("sess_1", { type: "text", to: "+9477", body: { text: "hi" } }),
    (e: unknown) => {
      assert.ok(e instanceof WALayerError);
      assert.equal(e.status, 409);
      assert.equal(e.code, "RECIPIENT_SUPPRESSED");
      assert.equal(e.requestId, "req_1");
      return true;
    },
  );
});

test("204 responses resolve to undefined (delete)", async () => {
  const { fetch, calls } = stub(204, {});
  const wa = new WALayer({ apiKey: "k", fetch });
  const r = await wa.webhooks.delete("wh_1");
  assert.equal(r, undefined);
  assert.equal(calls[0]!.method, "DELETE");
});

test("constructing without an apiKey throws", () => {
  assert.throws(() => new WALayer({ apiKey: "" }), /apiKey is required/);
});

test("channels.send targets the channel path with no `to` in the body", async () => {
  const { fetch, calls } = stub(202, { data: { id: "msg_9", status: "queued" } });
  const wa = new WALayer({ apiKey: "k", baseUrl: "https://api.example.com", fetch });
  await wa.channels.send("sess_1", "120363144742733222", { type: "text", body: { text: "hi" } });
  const call = calls[0]!;
  assert.equal(call.url, "https://api.example.com/v1/sessions/sess_1/channels/120363144742733222/messages");
  assert.ok(call.headers["idempotency-key"]);
  assert.deepEqual(call.body, { type: "text", body: { text: "hi" } });
});

// ── Phase 5–10 surface: verify every new method maps to the right call ──────
test("new WhatsApp methods map to the correct verb, path and body", async () => {
  const { fetch, calls } = stub(200, { data: {} });
  const wa = new WALayer({ apiKey: "k", baseUrl: "https://api.example.com", fetch });
  const S = "sess_1", C = "9477@s.whatsapp.net", NL = "1@newsletter", COM = "1@g.us";

  // sessions
  await wa.sessions.settings(S);
  await wa.sessions.resetSettings(S);
  await wa.sessions.limits(S);
  await wa.sessions.pair(S, { method: "qr" });
  // messages / actions / stories
  await wa.messages.story(S, { type: "text", body: { text: "hi" } }, "k1");
  await wa.messages.star("msg_1", true);
  await wa.messages.pin("msg_1", { duration_seconds: 604800 });
  await wa.messages.markRead("msg_1");
  await wa.messages.markPlayed("msg_1");
  await wa.messages.receipts("msg_1");
  // chats
  await wa.inbox.getChat(S, C);
  await wa.inbox.archiveChat(S, C, true);
  await wa.inbox.patchChat(S, C, { pinned: true });
  await wa.inbox.presence(S, C, "typing");
  await wa.inbox.deleteChat(S, C);
  // contacts / identity / presence
  await wa.contacts.check(S, ["+9477"]);
  await wa.contacts.resolve(S, [C]);
  await wa.contacts.blocklist(S);
  await wa.contacts.about(S, C);
  await wa.contacts.profile(S, C);
  await wa.contacts.subscribePresence(S, C);
  await wa.contacts.ownProfile(S);
  await wa.contacts.updateProfile(S, { about: "hi" });
  await wa.contacts.setPresence(S, "online");
  // groups
  await wa.groups.settings(S, "grp_1", { announce_only: true });
  await wa.groups.requests(S, "grp_1");
  await wa.groups.resolveRequests(S, "grp_1", "approve", [C]);
  await wa.groups.revokeInvite(S, "grp_1");
  await wa.groups.setIcon(S, "grp_1", "med_1");
  // communities
  await wa.communities.create(S, { name: "HQ" });
  await wa.communities.subgroups(S, COM);
  await wa.communities.linkGroup(S, COM, "2@g.us");
  await wa.communities.updateParticipants(S, COM, { action: "add", participants: [C] });
  // channels
  await wa.channels.list(S);
  await wa.channels.create(S, { name: "News" });
  await wa.channels.subscribe(S, NL);
  await wa.channels.react(S, NL, 7, "🔥");
  await wa.channels.track(S, NL);
  // labels
  await wa.labels.create(S, { name: "VIP", color: 2 });
  await wa.labels.associate(S, "31", { chat_jid: C });
  // business / calls / bots
  await wa.business.profile(S, C);
  await wa.business.rejectCall(S, "call_1", C);
  await wa.business.bots(S);
  // media / webhooks / events
  await wa.media.list();
  await wa.webhooks.test("wh_1");
  await wa.events.types();

  const find = (m: string, u: string) => calls.find((c) => c.method === m && c.url.endsWith(u));
  assert.ok(find("GET", "/v1/sessions/sess_1/settings"), "settings");
  assert.ok(find("POST", "/v1/sessions/sess_1/stories"), "story");
  assert.ok(find("POST", "/v1/messages/msg_1/star"), "star");
  assert.ok(find("POST", "/v1/messages/msg_1/pin"), "pin");
  assert.ok(find("GET", "/v1/messages/msg_1/receipts"), "receipts");
  assert.ok(find("PATCH", `/v1/sessions/sess_1/chats/${encodeURIComponent(C)}`), "patchChat");
  assert.ok(find("POST", "/v1/sessions/sess_1/contacts/check"), "contacts.check");
  assert.ok(find("POST", "/v1/sessions/sess_1/contacts/resolve"), "contacts.resolve");
  assert.ok(find("GET", "/v1/sessions/sess_1/blocklist"), "blocklist");
  assert.ok(find("PATCH", "/v1/sessions/sess_1/profile"), "updateProfile");
  assert.ok(find("POST", "/v1/sessions/sess_1/presence"), "setPresence");
  assert.ok(find("PATCH", "/v1/sessions/sess_1/groups/grp_1/settings"), "group settings");
  assert.ok(find("PUT", "/v1/sessions/sess_1/groups/grp_1/icon"), "setIcon");
  assert.ok(find("POST", `/v1/sessions/sess_1/communities/${encodeURIComponent(COM)}/subgroups`), "linkGroup");
  assert.ok(find("POST", `/v1/sessions/sess_1/channels/${encodeURIComponent(NL)}/messages/7/react`), "channel react");
  assert.ok(find("POST", "/v1/sessions/sess_1/labels/31/associations"), "label associate");
  assert.ok(find("POST", "/v1/sessions/sess_1/calls/call_1/reject"), "rejectCall");
  assert.ok(find("GET", "/v1/sessions/sess_1/bots"), "bots");
  assert.ok(find("GET", "/v1/media"), "media list");
  assert.ok(find("POST", "/v1/webhooks/wh_1/test"), "webhook test");
  assert.ok(find("GET", "/v1/events/types"), "event types");

  // The story call carries an idempotency key.
  assert.ok(find("POST", "/v1/sessions/sess_1/stories")!.headers["idempotency-key"], "story idem");
});
