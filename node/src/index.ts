import { randomUUID } from "node:crypto";
import { Http, type HttpOptions, type Page } from "./http.js";
import type { Session, SendInput, SendResult, Webhook, Suppression } from "./types.js";

export { WALayerError } from "./errors.js";
export { verifyWebhook } from "./webhook.js";
export type { VerifyResult, VerifyOptions } from "./webhook.js";
export type { Session, SendInput, SendResult, Webhook, Suppression } from "./types.js";
export type { Page } from "./http.js";

export interface WALayerOptions extends HttpOptions {}

/** Anything the API returns that the SDK does not model field-by-field. */
type Json = Record<string, unknown>;

/**
 * The WALayer client. Three lines to send:
 *
 *   import { WALayer } from "@walayer/sdk";
 *   const wa = new WALayer({ apiKey: process.env.WALAYER_API_KEY! });
 *   await wa.messages.send(sessionId, { type: "text", to: "+9477…", body: { text: "hi" } });
 *
 * ## Scope: WhatsApp actions only
 *
 * This SDK covers what you can DO with a linked WhatsApp number — connect it,
 * send and read messages, manage groups and channels, block a contact, receive
 * events. It deliberately does NOT wrap the platform's own surface: billing,
 * plans and usage, the CRM (contact profiles, custom fields, tags, segments,
 * timelines), cross-tenant search, or anything under the dashboard's
 * cookie-authenticated routes. Those are WALayer product features, not WhatsApp
 * ones, and an SDK that mixes them teaches an integrator that "WhatsApp" and
 * "our account system" are the same thing. They are reachable over plain REST
 * for anyone who wants them.
 *
 * Where the API returns a paged envelope the method returns it whole
 * (`{data, next_cursor}`) rather than just the rows — an SDK that hides the
 * cursor cannot page.
 */
export class WALayer {
  readonly sessions: Sessions;
  readonly messages: Messages;
  readonly inbox: Inbox;
  readonly groups: Groups;
  readonly media: Media;
  readonly webhooks: Webhooks;
  readonly channels: Channels;
  readonly suppressions: Suppressions;
  readonly events: Events;
  readonly contacts: Contacts;
  readonly communities: Communities;
  readonly labels: Labels;
  readonly business: Business;

  constructor(options: WALayerOptions) {
    const http = new Http(options);
    this.sessions = new Sessions(http);
    this.messages = new Messages(http);
    this.inbox = new Inbox(http);
    this.groups = new Groups(http);
    this.media = new Media(http);
    this.webhooks = new Webhooks(http);
    this.channels = new Channels(http);
    this.suppressions = new Suppressions(http);
    this.events = new Events(http);
    this.contacts = new Contacts(http);
    this.communities = new Communities(http);
    this.labels = new Labels(http);
    this.business = new Business(http);
  }

}

class Sessions {
  constructor(private readonly http: Http) {}
  list(): Promise<Session[]> {
    return this.http.request("/v1/sessions");
  }
  get(id: string): Promise<Session> {
    return this.http.request(`/v1/sessions/${enc(id)}`);
  }
  create(input: { country: string; label?: string }): Promise<Session> {
    return this.http.request("/v1/sessions", { method: "POST", body: input });
  }
  /** Partial update. `pacing` is MERGED, not replaced (docs/04 §4.4). */
  update(id: string, input: Json): Promise<Session> {
    return this.http.request(`/v1/sessions/${enc(id)}`, { method: "PATCH", body: input });
  }
  /** Graceful logout and credential shred. */
  delete(id: string): Promise<void> {
    return this.http.request(`/v1/sessions/${enc(id)}`, { method: "DELETE" });
  }
  logout(id: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(id)}/logout`, { method: "POST" });
  }
  /** Trust score, warmup stage and delivery stats (FR-033/262). */
  health(id: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(id)}/health`);
  }
  /**
   * Is each number registered on WhatsApp? Capped at 50 per call — this is the
   * endpoint that would otherwise be a number-validity oracle (docs/04 §9).
   */
  onWhatsApp(id: string, phones: string[]): Promise<{ results: Json[] }> {
    return this.http.request(`/v1/sessions/${enc(id)}/on-whatsapp`, {
      method: "POST",
      body: { phones },
    });
  }
  /** The settable half of the session (label, pacing, caps, warmup). */
  settings(id: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(id)}/settings`);
  }
  /** Reset settings to plan defaults. Warmup stage is earned and never resets up. */
  resetSettings(id: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(id)}/settings/reset`, { method: "POST" });
  }
  /** Current send caps & warmup limits (the "reachout timelock", as data). */
  limits(id: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(id)}/limits`);
  }
  /** Start pairing. Returns the first frame; use the SSE stream to watch it. */
  pair(id: string, input: { method?: "qr" | "code"; phone_e164?: string } = {}): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(id)}/pair`, { method: "POST", body: input });
  }
  exportData(id: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(id)}/export`);
  }
}

class Messages {
  constructor(private readonly http: Http) {}

  /**
   * Send one message.
   *
   * The idempotency key defaults to a fresh uuid, which makes a retry of THIS
   * call a new message. Pass your own — derived from whatever you are reacting
   * to — when a retry must not send twice (invariant I4).
   */
  send(sessionId: string, input: SendInput, idempotencyKey: string = randomUUID()): Promise<SendResult> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/messages`, {
      method: "POST",
      body: input,
      headers: { "idempotency-key": idempotencyKey },
    });
  }

  /** A paced campaign. Recipients already suppressed are dropped server-side. */
  bulk(
    sessionId: string,
    input: { name?: string; template: { type: string; body: Json }; recipients: Json[] },
    idempotencyKey: string = randomUUID(),
  ): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/messages/bulk`, {
      method: "POST",
      body: input,
      headers: { "idempotency-key": idempotencyKey },
    });
  }

  /** The message log. Returns the envelope so `next_cursor` survives. */
  list(
    params: {
      session?: string;
      status?: string;
      direction?: "in" | "out";
      type?: string;
      q?: string;
      from?: number;
      to?: number;
      limit?: number;
      cursor?: string;
    } = {},
  ): Promise<Page<Json>> {
    return this.http.envelope("/v1/messages", { query: params });
  }

  get(id: string): Promise<Json> {
    return this.http.request(`/v1/messages/${enc(id)}`);
  }

  /** Resend an undelivered message. A delivered one is refused (docs/04 §7). */
  resend(id: string): Promise<Json> {
    return this.http.request(`/v1/messages/${enc(id)}/resend`, { method: "POST" });
  }
  /** Delivery / read receipts for a message. */
  receipts(id: string): Promise<Json> {
    return this.http.request(`/v1/messages/${enc(id)}/receipts`);
  }

  /**
   * Publish a status/story. `type` is text, image, video or audio. Story
   * presentation (background, font) goes under `options.story`.
   */
  story(
    sessionId: string,
    input: { type: "text" | "image" | "video" | "audio"; body: Json; options?: Json },
    idempotencyKey: string = randomUUID(),
  ): Promise<SendResult> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/stories`, {
      method: "POST",
      body: input,
      headers: { "idempotency-key": idempotencyKey },
    });
  }

  /** Star or unstar a message (default: star). */
  star(id: string, starred = true): Promise<Json> {
    return this.http.request(`/v1/messages/${enc(id)}/star`, { method: "POST", body: { starred } });
  }
  /** Pin or unpin a message. `duration_seconds` ∈ {86400, 604800, 2592000}. */
  pin(id: string, opts: { pinned?: boolean; duration_seconds?: number } = {}): Promise<Json> {
    return this.http.request(`/v1/messages/${enc(id)}/pin`, { method: "POST", body: opts });
  }
  /** Send a read receipt for an inbound message. */
  markRead(id: string): Promise<Json> {
    return this.http.request(`/v1/messages/${enc(id)}/read`, { method: "POST" });
  }
  /** Send a played receipt for an inbound voice note. */
  markPlayed(id: string): Promise<Json> {
    return this.http.request(`/v1/messages/${enc(id)}/played`, { method: "POST" });
  }
}

class Inbox {
  constructor(private readonly http: Http) {}
  /** Conversations, newest first. `q` searches name, group subject and number. */
  chats(
    sessionId: string,
    params: { q?: string; unread?: boolean; kind?: "group" | "direct"; limit?: number } = {},
  ): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/chats`, { query: params });
  }
  /** One conversation, oldest first. `q` searches within it. */
  messages(
    sessionId: string,
    chatJid: string,
    params: { q?: string; limit?: number } = {},
  ): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/chats/${enc(chatJid)}/messages`, {
      query: params,
    });
  }
  markRead(sessionId: string, chatJid: string): Promise<void> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/chats/${enc(chatJid)}/read`, {
      method: "POST",
    });
  }
  contacts(sessionId: string): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts`);
  }
  upsertContact(sessionId: string, jid: string, input: Json): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}`, {
      method: "PUT",
      body: input,
    });
  }
  /** Records the intent; `202` means recorded, not performed (docs/04 §3.3). */
  block(sessionId: string, jid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}/block`, {
      method: "POST",
    });
  }
  unblock(sessionId: string, jid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}/unblock`, {
      method: "POST",
    });
  }
  /** One chat with its state (archived, pinned, muted, disappearing timer). */
  getChat(sessionId: string, chatJid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/chats/${enc(chatJid)}`);
  }
  /** Delete a chat (soft locally; the app-state patch syncs upstream). */
  deleteChat(sessionId: string, chatJid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/chats/${enc(chatJid)}`, { method: "DELETE" });
  }
  /** Archive or unarchive a chat (default: archive). */
  archiveChat(sessionId: string, chatJid: string, archived = true): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/chats/${enc(chatJid)}/archive`, {
      method: "POST",
      body: { archived },
    });
  }
  /** Pin, mute, mark unread, or set the disappearing timer. Only set keys change. */
  patchChat(
    sessionId: string,
    chatJid: string,
    input: { pinned?: boolean; muted?: boolean; mute_until?: number; unread?: boolean; disappearing_seconds?: number },
  ): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/chats/${enc(chatJid)}`, { method: "PATCH", body: input });
  }
  /** Send typing / recording / paused presence into a chat. */
  presence(sessionId: string, chatJid: string, state: "typing" | "recording" | "paused"): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/chats/${enc(chatJid)}/presence`, {
      method: "POST",
      body: { state },
    });
  }
}

class Groups {
  constructor(private readonly http: Http) {}
  list(sessionId: string): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups`);
  }
  create(sessionId: string, input: { subject: string; description?: string; participants?: string[] }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups`, { method: "POST", body: input });
  }
  /** `gid` accepts the group JID or `grp_<uuid>`. */
  get(sessionId: string, gid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}`);
  }
  participants(
    sessionId: string,
    gid: string,
    input: { action: "add" | "remove" | "promote" | "demote"; participants: string[] },
  ): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/participants`, {
      method: "POST",
      body: input,
    });
  }
  invite(sessionId: string, gid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/invite`);
  }
  acceptInvite(sessionId: string, code: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/invite/accept`, {
      method: "POST",
      body: { code },
    });
  }
  leave(sessionId: string, gid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/leave`, { method: "POST" });
  }
  /** Straight from WhatsApp, with an authoritative participant list (FR-181). */
  live(sessionId: string, gid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/live`);
  }
  joined(sessionId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/joined`);
  }
  /** Update subject and/or description. */
  update(sessionId: string, gid: string, input: { subject?: string; description?: string }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}`, { method: "PATCH", body: input });
  }
  getIcon(sessionId: string, gid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/icon`);
  }
  /** Set the group icon from an uploaded media id (must be a JPEG). */
  setIcon(sessionId: string, gid: string, mediaId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/icon`, {
      method: "PUT",
      body: { media: { media_id: mediaId } },
    });
  }
  deleteIcon(sessionId: string, gid: string): Promise<void> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/icon`, { method: "DELETE" });
  }
  /** announce_only, locked, join_approval (booleans) and member_add mode. */
  settings(
    sessionId: string,
    gid: string,
    input: { announce_only?: boolean; locked?: boolean; join_approval?: boolean; member_add?: "all_members" | "admins_only" },
  ): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/settings`, { method: "PATCH", body: input });
  }
  requests(sessionId: string, gid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/requests`);
  }
  /** Approve or reject pending join requests, per participant. */
  resolveRequests(sessionId: string, gid: string, action: "approve" | "reject", participants: string[]): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/requests`, {
      method: "POST",
      body: { action, participants },
    });
  }
  /** Revoke the current invite link and return the replacement. */
  revokeInvite(sessionId: string, gid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/invite`, { method: "DELETE" });
  }
  /** Group metadata from an invite code, without joining. */
  inviteInfo(sessionId: string, code: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/invite/${enc(code)}`);
  }
  /** Send the invite link to someone as an ordinary message. */
  sendInvite(sessionId: string, gid: string, to: string, text?: string, idempotencyKey: string = randomUUID()): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/groups/${enc(gid)}/invite/send`, {
      method: "POST",
      body: { to, ...(text ? { text } : {}) },
      headers: { "idempotency-key": idempotencyKey },
    });
  }
}

class Media {
  constructor(private readonly http: Http) {}
  /**
   * Upload by base64 or by URL. The URL path is SSRF-guarded server-side, so a
   * customer-supplied address cannot reach private ranges (docs/04 §5.4).
   */
  upload(input: {
    session_id?: string;
    filename?: string;
    mime_type?: string;
    data_base64?: string;
    url?: string;
  }): Promise<Json> {
    return this.http.request("/v1/media", { method: "POST", body: input });
  }
  /** A short-lived read URL. The object key itself is never returned. */
  get(id: string): Promise<Json> {
    return this.http.request(`/v1/media/${enc(id)}`);
  }
  /** The media library (metadata only). */
  list(params: { session?: string; direction?: "in" | "out"; limit?: number } = {}): Promise<Json[]> {
    return this.http.request("/v1/media", { query: params });
  }
  delete(id: string): Promise<void> {
    return this.http.request(`/v1/media/${enc(id)}`, { method: "DELETE" });
  }
}

class Webhooks {
  constructor(private readonly http: Http) {}
  list(): Promise<Webhook[]> {
    return this.http.request("/v1/webhooks");
  }
  /** The signing secret is returned ONCE, here. It is never readable again. */
  create(input: { url: string; events: string[]; session_id?: string }): Promise<Webhook> {
    return this.http.request("/v1/webhooks", { method: "POST", body: input });
  }
  update(id: string, input: { url?: string; events?: string[]; enabled?: boolean }): Promise<Webhook> {
    return this.http.request(`/v1/webhooks/${enc(id)}`, { method: "PATCH", body: input });
  }
  delete(id: string): Promise<void> {
    return this.http.request(`/v1/webhooks/${enc(id)}`, { method: "DELETE" });
  }
  /** Fire a signed test delivery at the endpoint now. */
  test(id: string): Promise<Json> {
    return this.http.request(`/v1/webhooks/${enc(id)}/test`, { method: "POST" });
  }
}

class Channels {
  constructor(private readonly http: Http) {}
  /** Channels this number follows. */
  list(sessionId: string): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels`);
  }
  create(sessionId: string, input: { name: string; description?: string }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels`, { method: "POST", body: input });
  }
  get(sessionId: string, channelId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}`);
  }
  /** Mute or unmute a channel. */
  mute(sessionId: string, channelId: string, muted: boolean): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}`, {
      method: "PATCH",
      body: { muted },
    });
  }
  /** Unfollow a channel (a companion client cannot hard-delete one). */
  delete(sessionId: string, channelId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}`, { method: "DELETE" });
  }
  subscribe(sessionId: string, channelId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/subscribe`, { method: "POST" });
  }
  unsubscribe(sessionId: string, channelId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/unsubscribe`, { method: "POST" });
  }
  /** Channel info from an invite code, without following. */
  inviteInfo(sessionId: string, code: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/invite/${enc(code)}`);
  }
  subscribeByInvite(sessionId: string, code: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/invite/${enc(code)}/subscribe`, { method: "POST" });
  }
  messages(sessionId: string, channelId: string, params: { limit?: number; before?: number } = {}): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/messages`, { query: params });
  }
  updates(sessionId: string, channelId: string, params: { limit?: number; since?: number } = {}): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/updates`, { query: params });
  }
  /** Subscribe this connection to live reaction/view updates. */
  track(sessionId: string, channelId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/track`, { method: "POST" });
  }
  /** React to a channel message by its numeric server id. Empty emoji removes. */
  react(sessionId: string, channelId: string, serverId: number, emoji: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/messages/${serverId}/react`, {
      method: "POST",
      body: { emoji },
    });
  }
  markViewed(sessionId: string, channelId: string, serverId: number): Promise<void> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/messages/${serverId}/view`, { method: "POST" });
  }
  sendInvite(sessionId: string, channelId: string, to: string, text?: string, idempotencyKey: string = randomUUID()): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/invite/send`, {
      method: "POST",
      body: { to, ...(text ? { text } : {}) },
      headers: { "idempotency-key": idempotencyKey },
    });
  }
  /** Post a message to a channel. */
  send(
    sessionId: string,
    channelId: string,
    input: { type: string; body: Json; options?: { schedule_at?: number } },
    idempotencyKey: string = randomUUID(),
  ): Promise<SendResult> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/channels/${enc(channelId)}/messages`, {
      method: "POST",
      body: input,
      headers: { "idempotency-key": idempotencyKey },
    });
  }
}

class Suppressions {
  constructor(private readonly http: Http) {}
  list(): Promise<Suppression[]> {
    return this.http.request("/v1/suppressions");
  }
  add(phone: string, reason?: string): Promise<{ phone: string; suppressed: boolean }> {
    return this.http.request("/v1/suppressions", { method: "POST", body: { phone, reason } });
  }
  remove(phone: string): Promise<void> {
    return this.http.request(`/v1/suppressions/${enc(phone)}`, { method: "DELETE" });
  }
}

class Events {
  constructor(private readonly http: Http) {}
  list(params: { since?: number; limit?: number } = {}): Promise<Json[]> {
    return this.http.request("/v1/events", { query: params });
  }
  /** The event catalogue — every type a webhook may carry. */
  types(): Promise<string[]> {
    return this.http.request("/v1/events/types");
  }
  /** Re-deliver one event. Your handler must be idempotent on X-Delivery-Id. */
  redeliver(id: string): Promise<Json> {
    return this.http.request(`/v1/events/${enc(id)}/redeliver`, { method: "POST" });
  }
}


/**
 * WhatsApp identity, presence and contact checks — distinct from the platform
 * CRM (which is not part of this SDK). These act on the linked number and its
 * WhatsApp contacts.
 */
class Contacts {
  constructor(private readonly http: Http) {}
  /** Are these numbers on WhatsApp? Capped at 50 per call. */
  check(sessionId: string, phones: string[]): Promise<{ results: Json[] }> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/check`, { method: "POST", body: { phones } });
  }
  /** Resolve LID ↔ phone JID, per entry, from the session's own signal store. */
  resolve(sessionId: string, jids: string[]): Promise<{ results: Json[] }> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/resolve`, { method: "POST", body: { jids } });
  }
  block(sessionId: string, jid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}/block`, { method: "POST" });
  }
  unblock(sessionId: string, jid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}/unblock`, { method: "POST" });
  }
  /** Recorded + live blocklist. */
  blocklist(sessionId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/blocklist`);
  }
  about(sessionId: string, jid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}/about`);
  }
  profile(sessionId: string, jid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}/profile`);
  }
  /** Last observed presence (subscribe first, then read as events arrive). */
  presence(sessionId: string, jid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}/presence`);
  }
  subscribePresence(sessionId: string, jid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/contacts/${enc(jid)}/presence/subscribe`, { method: "POST" });
  }
  /** The linked number's OWN profile. */
  ownProfile(sessionId: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/profile`);
  }
  /** Update the linked number's push name and/or about text. */
  updateProfile(sessionId: string, input: { push_name?: string; about?: string }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/profile`, { method: "PATCH", body: input });
  }
  /** Set the linked number's own online / offline presence. */
  setPresence(sessionId: string, state: "online" | "offline"): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/presence`, { method: "POST", body: { state } });
  }
}

/** WhatsApp Communities — parent groups with linked subgroups. */
class Communities {
  constructor(private readonly http: Http) {}
  list(sessionId: string): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities`);
  }
  create(sessionId: string, input: { name: string; description?: string }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities`, { method: "POST", body: input });
  }
  get(sessionId: string, cid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}`);
  }
  update(sessionId: string, cid: string, input: { name?: string; description?: string }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}`, { method: "PATCH", body: input });
  }
  /** Deactivate (leave as owner). */
  deactivate(sessionId: string, cid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}`, { method: "DELETE" });
  }
  subgroups(sessionId: string, cid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}/subgroups`);
  }
  linkGroup(sessionId: string, cid: string, groupJid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}/subgroups`, {
      method: "POST",
      body: { group_jid: groupJid },
    });
  }
  unlinkGroup(sessionId: string, cid: string, gid: string): Promise<void> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}/subgroups/${enc(gid)}`, { method: "DELETE" });
  }
  joinSubgroup(sessionId: string, cid: string, gid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}/subgroups/${enc(gid)}/join`, { method: "POST" });
  }
  createGroup(sessionId: string, cid: string, input: { subject: string; participants?: string[] }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}/groups`, { method: "POST", body: input });
  }
  participants(sessionId: string, cid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}/participants`);
  }
  updateParticipants(
    sessionId: string,
    cid: string,
    input: { action: "add" | "remove" | "promote" | "demote"; participants: string[] },
  ): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}/participants`, { method: "POST", body: input });
  }
  revokeInvite(sessionId: string, cid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/communities/${enc(cid)}/invite`, { method: "DELETE" });
  }
}

/** WhatsApp Business labels — coloured, per-number, attached to chats & messages. */
class Labels {
  constructor(private readonly http: Http) {}
  list(sessionId: string): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/labels`);
  }
  create(sessionId: string, input: { name: string; color?: number; id?: string }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/labels`, { method: "POST", body: input });
  }
  update(sessionId: string, labelId: string, input: { name?: string; color?: number }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/labels/${enc(labelId)}`, { method: "PATCH", body: input });
  }
  delete(sessionId: string, labelId: string): Promise<void> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/labels/${enc(labelId)}`, { method: "DELETE" });
  }
  associations(sessionId: string, labelId: string): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/labels/${enc(labelId)}/associations`);
  }
  /** Label a chat, or a specific message within a chat. */
  associate(sessionId: string, labelId: string, target: { chat_jid: string; wa_msg_id?: string }): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/labels/${enc(labelId)}/associations`, { method: "POST", body: target });
  }
  dissociate(sessionId: string, labelId: string, target: { chat_jid: string; wa_msg_id?: string }): Promise<void> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/labels/${enc(labelId)}/associations`, { method: "DELETE", body: target });
  }
}

/** Business reads, calls and bots. */
class Business {
  constructor(private readonly http: Http) {}
  /** A business profile — the linked number's own, or `jid`. */
  profile(sessionId: string, jid?: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/business/profile`, { query: jid ? { jid } : {} });
  }
  /** Items of an order message. `token` comes from that message. */
  order(sessionId: string, orderId: string, token: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/business/orders/${enc(orderId)}`, { query: { token } });
  }
  /** Resolve a wa.me/message business link. */
  resolveLink(sessionId: string, code: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/business/link/${enc(code)}`);
  }
  /** Reject an incoming call. The id and caller come from the call.received event. */
  rejectCall(sessionId: string, callId: string, callerJid: string): Promise<Json> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/calls/${enc(callId)}/reject`, {
      method: "POST",
      body: { caller_jid: callerJid },
    });
  }
  /** Meta AI bots visible to this number. */
  bots(sessionId: string): Promise<Json[]> {
    return this.http.request(`/v1/sessions/${enc(sessionId)}/bots`);
  }
}

/**
 * Path segments are encoded because several of them are not opaque ids: a chat
 * jid contains `@`, a phone may carry `+`, and a field key is customer-chosen.
 * Unencoded, `+9477…` arrives as a space and the lookup silently misses.
 */
function enc(segment: string): string {
  return encodeURIComponent(segment);
}
