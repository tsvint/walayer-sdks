package walayer

import (
	"context"
	"net/url"
)

// The resource groups mirror the TypeScript, Python and PHP SDKs one for one,
// so a team running two of them can read either. Method names are the same in
// Go casing; the receiver is the only structural difference.
//
// Bodies and responses are `M` (map[string]any) rather than generated structs.
// The API surface is wide and still moving, and a struct per message type would
// be stale within a release — see the note on M in client.go. Callers who want
// types should define their own and unmarshal into them.

// ---------------------------------------------------------------- sessions

// Sessions manages connected WhatsApp numbers.
type Sessions struct{ http *httpClient }

func (s *Sessions) List(ctx context.Context) ([]M, error) {
	var out []M
	err := s.http.into(ctx, "/v1/sessions", "GET", nil, nil, nil, &out)
	return out, err
}

// Create registers a number. Country is metadata for warmup pacing and
// reporting, not an allocation key.
func (s *Sessions) Create(ctx context.Context, country, label string) (M, error) {
	body := M{"country": country}
	if label != "" {
		body["label"] = label
	}
	var out M
	err := s.http.into(ctx, "/v1/sessions", "POST", body, nil, nil, &out)
	return out, err
}

func (s *Sessions) Get(ctx context.Context, id string) (M, error) {
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id), "GET", nil, nil, nil, &out)
	return out, err
}

func (s *Sessions) Update(ctx context.Context, id string, fields M) (M, error) {
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id), "PATCH", fields, nil, nil, &out)
	return out, err
}

// Delete removes the session and unlinks the number.
func (s *Sessions) Delete(ctx context.Context, id string) error {
	return s.http.into(ctx, "/v1/sessions/"+seg(id), "DELETE", nil, nil, nil, nil)
}

// Pair starts linking. Method is "qr" or "code"; phoneE164 is required for
// "code" and ignored for "qr".
func (s *Sessions) Pair(ctx context.Context, id, method, phoneE164 string) (M, error) {
	body := M{}
	if method != "" {
		body["method"] = method
	}
	if phoneE164 != "" {
		body["phone_e164"] = phoneE164
	}
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id)+"/pair", "POST", body, nil, nil, &out)
	return out, err
}

// Logout ends the session gracefully and shreds credentials. The number must
// be paired again afterwards.
func (s *Sessions) Logout(ctx context.Context, id string) (M, error) {
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id)+"/logout", "POST", nil, nil, nil, &out)
	return out, err
}

func (s *Sessions) Health(ctx context.Context, id string) (M, error) {
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id)+"/health", "GET", nil, nil, nil, &out)
	return out, err
}

func (s *Sessions) Settings(ctx context.Context, id string) (M, error) {
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id)+"/settings", "GET", nil, nil, nil, &out)
	return out, err
}

func (s *Sessions) ResetSettings(ctx context.Context, id string) (M, error) {
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id)+"/settings", "DELETE", nil, nil, nil, &out)
	return out, err
}

func (s *Sessions) Limits(ctx context.Context, id string) (M, error) {
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id)+"/limits", "GET", nil, nil, nil, &out)
	return out, err
}

// OnWhatsApp checks which of the given numbers are reachable on WhatsApp.
func (s *Sessions) OnWhatsApp(ctx context.Context, id string, phones []string) (M, error) {
	var out M
	err := s.http.into(ctx, "/v1/sessions/"+seg(id)+"/on-whatsapp", "POST", M{"phones": phones}, nil, nil, &out)
	return out, err
}

// ---------------------------------------------------------------- messages

// Messages sends and inspects messages.
type Messages struct{ http *httpClient }

// Send delivers one message.
//
// idempotencyKey is REQUIRED by the API and is the reason a network retry does
// not become a second WhatsApp message to a real person. Use something derived
// from your own domain — an order id, not a random UUID generated per attempt,
// which would defeat the point.
func (m *Messages) Send(ctx context.Context, sessionID string, message M, idempotencyKey string) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/messages", "POST", message, nil,
		map[string]string{"Idempotency-Key": idempotencyKey}, &out)
	return out, err
}

// Bulk queues one template against many recipients.
func (m *Messages) Bulk(ctx context.Context, sessionID string, template M, recipients []M, idempotencyKey string) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/messages/bulk", "POST",
		M{"template": template, "recipients": recipients}, nil,
		map[string]string{"Idempotency-Key": idempotencyKey}, &out)
	return out, err
}

func (m *Messages) List(ctx context.Context, params M) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/messages", "GET", nil, query(params), nil, &out)
	return out, err
}

func (m *Messages) Get(ctx context.Context, messageID string) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/messages/"+seg(messageID), "GET", nil, nil, nil, &out)
	return out, err
}

// Resend re-queues a message that failed with a retryable error. It does NOT
// bypass idempotency, and it is not the right response to an unknown outcome.
func (m *Messages) Resend(ctx context.Context, messageID string) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/messages/"+seg(messageID)+"/resend", "POST", nil, nil, nil, &out)
	return out, err
}

func (m *Messages) Receipts(ctx context.Context, messageID string) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/messages/"+seg(messageID)+"/receipts", "GET", nil, nil, nil, &out)
	return out, err
}

func (m *Messages) MarkRead(ctx context.Context, messageID string) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/messages/"+seg(messageID)+"/read", "POST", nil, nil, nil, &out)
	return out, err
}

// ---------------------------------------------------------------- inbox

// Inbox reads chats, contacts and presence.
type Inbox struct{ http *httpClient }

func (i *Inbox) Chats(ctx context.Context, sessionID string, params M) (M, error) {
	var out M
	err := i.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/chats", "GET", nil, query(params), nil, &out)
	return out, err
}

func (i *Inbox) Messages(ctx context.Context, sessionID, chatJID string, params M) (M, error) {
	q := query(params)
	q.Set("chat_jid", chatJID)
	var out M
	err := i.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/chat-messages", "GET", nil, q, nil, &out)
	return out, err
}

func (i *Inbox) MarkChatRead(ctx context.Context, sessionID, chatJID string) error {
	return i.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/chats/"+seg(chatJID)+"/read", "POST", nil, nil, nil, nil)
}

func (i *Inbox) Contacts(ctx context.Context, sessionID string) ([]M, error) {
	var out []M
	err := i.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/contacts", "GET", nil, nil, nil, &out)
	return out, err
}

func (i *Inbox) Block(ctx context.Context, sessionID, jid string) (M, error) {
	var out M
	err := i.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/contacts/"+seg(jid)+"/block", "POST", nil, nil, nil, &out)
	return out, err
}

func (i *Inbox) Unblock(ctx context.Context, sessionID, jid string) (M, error) {
	var out M
	err := i.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/contacts/"+seg(jid)+"/block", "DELETE", nil, nil, nil, &out)
	return out, err
}

// Presence sets typing / recording / online state on a chat.
func (i *Inbox) Presence(ctx context.Context, sessionID, chatJID, state string) (M, error) {
	var out M
	err := i.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/chats/"+seg(chatJID)+"/presence", "POST",
		M{"state": state}, nil, nil, &out)
	return out, err
}

// ---------------------------------------------------------------- media

// Media uploads and manages attachments.
type Media struct{ http *httpClient }

func (m *Media) Upload(ctx context.Context, fields M) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/media", "POST", fields, nil, nil, &out)
	return out, err
}

func (m *Media) Get(ctx context.Context, mediaID string) (M, error) {
	var out M
	err := m.http.into(ctx, "/v1/media/"+seg(mediaID), "GET", nil, nil, nil, &out)
	return out, err
}

func (m *Media) List(ctx context.Context, params M) ([]M, error) {
	var out []M
	err := m.http.into(ctx, "/v1/media", "GET", nil, query(params), nil, &out)
	return out, err
}

func (m *Media) Delete(ctx context.Context, mediaID string) error {
	return m.http.into(ctx, "/v1/media/"+seg(mediaID), "DELETE", nil, nil, nil, nil)
}

// ---------------------------------------------------------------- channels

// Channels manages WhatsApp Channels, which have no official API equivalent.
type Channels struct{ http *httpClient }

func (c *Channels) List(ctx context.Context, sessionID string) ([]M, error) {
	var out []M
	err := c.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/channels", "GET", nil, nil, nil, &out)
	return out, err
}

func (c *Channels) Create(ctx context.Context, sessionID, name, description string) (M, error) {
	body := M{"name": name}
	if description != "" {
		body["description"] = description
	}
	var out M
	err := c.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/channels", "POST", body, nil, nil, &out)
	return out, err
}

func (c *Channels) Get(ctx context.Context, sessionID, channelID string) (M, error) {
	var out M
	err := c.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/channels/"+seg(channelID), "GET", nil, nil, nil, &out)
	return out, err
}

func (c *Channels) Delete(ctx context.Context, sessionID, channelID string) (M, error) {
	var out M
	err := c.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/channels/"+seg(channelID), "DELETE", nil, nil, nil, &out)
	return out, err
}

func (c *Channels) Subscribe(ctx context.Context, sessionID, channelID string) (M, error) {
	var out M
	err := c.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/channels/"+seg(channelID)+"/subscribe", "POST", nil, nil, nil, &out)
	return out, err
}

func (c *Channels) Unsubscribe(ctx context.Context, sessionID, channelID string) (M, error) {
	var out M
	err := c.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/channels/"+seg(channelID)+"/subscribe", "DELETE", nil, nil, nil, &out)
	return out, err
}

func (c *Channels) Send(ctx context.Context, sessionID, channelID string, message M, idempotencyKey string) (M, error) {
	var out M
	err := c.http.into(ctx, "/v1/sessions/"+seg(sessionID)+"/channels/"+seg(channelID)+"/messages", "POST",
		message, nil, map[string]string{"Idempotency-Key": idempotencyKey}, &out)
	return out, err
}

// ---------------------------------------------------------------- webhooks

// Webhooks manages delivery endpoints. To verify an incoming request, use
// VerifyWebhook rather than anything on this type.
type Webhooks struct{ http *httpClient }

func (w *Webhooks) List(ctx context.Context) ([]M, error) {
	var out []M
	err := w.http.into(ctx, "/v1/webhooks", "GET", nil, nil, nil, &out)
	return out, err
}

// Create registers an endpoint. sessionID scopes it to one number; empty means
// all of them.
func (w *Webhooks) Create(ctx context.Context, endpoint string, events []string, sessionID string) (M, error) {
	body := M{"url": endpoint, "events": events}
	if sessionID != "" {
		body["session_id"] = sessionID
	}
	var out M
	err := w.http.into(ctx, "/v1/webhooks", "POST", body, nil, nil, &out)
	return out, err
}

func (w *Webhooks) Update(ctx context.Context, webhookID string, fields M) (M, error) {
	var out M
	err := w.http.into(ctx, "/v1/webhooks/"+seg(webhookID), "PATCH", fields, nil, nil, &out)
	return out, err
}

func (w *Webhooks) Delete(ctx context.Context, webhookID string) error {
	return w.http.into(ctx, "/v1/webhooks/"+seg(webhookID), "DELETE", nil, nil, nil, nil)
}

// Test sends a signed test delivery to the endpoint.
func (w *Webhooks) Test(ctx context.Context, webhookID string) (M, error) {
	var out M
	err := w.http.into(ctx, "/v1/webhooks/"+seg(webhookID)+"/test", "POST", nil, nil, nil, &out)
	return out, err
}

// ---------------------------------------------------------------- suppressions

// Suppressions is the opt-out list. A suppressed recipient is never messaged
// again, and a send to one fails terminally rather than being retried.
type Suppressions struct{ http *httpClient }

func (s *Suppressions) List(ctx context.Context) ([]M, error) {
	var out []M
	err := s.http.into(ctx, "/v1/suppressions", "GET", nil, nil, nil, &out)
	return out, err
}

func (s *Suppressions) Add(ctx context.Context, phone, reason string) (M, error) {
	body := M{"phone": phone}
	if reason != "" {
		body["reason"] = reason
	}
	var out M
	err := s.http.into(ctx, "/v1/suppressions", "POST", body, nil, nil, &out)
	return out, err
}

// Remove takes a number off the opt-out list. Do this only on a recorded
// request from the recipient themselves.
func (s *Suppressions) Remove(ctx context.Context, phone string) error {
	q := url.Values{}
	q.Set("phone", phone)
	return s.http.into(ctx, "/v1/suppressions", "DELETE", nil, q, nil, nil)
}
