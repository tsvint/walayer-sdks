package walayer

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"strconv"
	"strings"
	"testing"
	"time"
)

// recorder is a Doer that captures the request and replays a canned response,
// so the suite needs no network and no API key.
type recorder struct {
	status   int
	body     string
	last     *http.Request
	lastBody string
}

func (r *recorder) Do(req *http.Request) (*http.Response, error) {
	r.last = req
	if req.Body != nil {
		raw, _ := io.ReadAll(req.Body)
		r.lastBody = string(raw)
	}
	status := r.status
	if status == 0 {
		status = 200
	}
	body := r.body
	if body == "" {
		body = `{"data":{}}`
	}
	return &http.Response{
		StatusCode: status,
		Body:       io.NopCloser(strings.NewReader(body)),
		Header:     make(http.Header),
	}, nil
}

func newTest(rec *recorder) *Client {
	return New("wal_test_key", WithHTTPClient(rec), WithBaseURL("https://api.example.test"))
}

// ---------------------------------------------------------------- transport

func TestSendCarriesAuthAndIdempotencyKey(t *testing.T) {
	rec := &recorder{body: `{"data":{"id":"msg_1","status":"queued"}}`}
	client := newTest(rec)

	out, err := client.Messages.Send(context.Background(), "sess_1",
		M{"type": "text", "to": "+94771234567", "body": "hi"}, "order-4417")
	if err != nil {
		t.Fatalf("send: %v", err)
	}

	if got := rec.last.Header.Get("Authorization"); got != "Bearer wal_test_key" {
		t.Errorf("Authorization = %q", got)
	}
	// The API requires this header. Without it a network retry becomes a second
	// WhatsApp message to a real person.
	if got := rec.last.Header.Get("Idempotency-Key"); got != "order-4417" {
		t.Errorf("Idempotency-Key = %q, want order-4417", got)
	}
	if out["id"] != "msg_1" {
		t.Errorf("envelope not unwrapped: %v", out)
	}
}

// A JID goes into the path, so a segment must not be able to escape its own
// position. `@` is deliberately NOT escaped — it is legal and unambiguous
// inside a path segment, and escaping it would only make URLs uglier. What
// matters is the separators.
func TestPathSegmentsCannotEscapeTheirPosition(t *testing.T) {
	rec := &recorder{}
	client := newTest(rec)

	_, _ = client.Inbox.Presence(context.Background(), "sess_1", "94771234567@s.whatsapp.net", "composing")
	path := rec.last.URL.EscapedPath()
	if path != "/v1/sessions/sess_1/chats/94771234567@s.whatsapp.net/presence" {
		t.Errorf("unexpected path: %s", path)
	}

	// The dangerous case: a value carrying a separator must not add segments or
	// start a query string.
	rec2 := &recorder{}
	client2 := newTest(rec2)
	_, _ = client2.Sessions.Get(context.Background(), "../../admin?x=1")

	escaped := rec2.last.URL.EscapedPath()
	if strings.Contains(escaped, "/../") || strings.HasSuffix(escaped, "/admin") {
		t.Errorf("a path traversal got through: %s", escaped)
	}
	if rec2.last.URL.RawQuery != "" {
		t.Errorf("a path value started a query string: %q", rec2.last.URL.RawQuery)
	}
}

func TestEmptyQueryValuesAreDropped(t *testing.T) {
	rec := &recorder{}
	client := newTest(rec)

	_, _ = client.Messages.List(context.Background(), M{"status": "sent", "session_id": "", "limit": 0})

	q := rec.last.URL.Query()
	if q.Get("status") != "sent" {
		t.Errorf("status missing: %v", q)
	}
	if _, present := q["session_id"]; present {
		t.Error("empty session_id should not be sent")
	}
	if _, present := q["limit"]; present {
		t.Error("zero limit should not be sent")
	}
}

func TestNoApiKeyFailsBeforeTheNetwork(t *testing.T) {
	rec := &recorder{}
	client := New("", WithHTTPClient(rec))

	if _, err := client.Sessions.List(context.Background()); err == nil {
		t.Fatal("expected an error with no api key")
	}
	if rec.last != nil {
		t.Error("a request was made without an api key")
	}
}

// ---------------------------------------------------------------- errors

func TestTypedErrorExposesTheStableCode(t *testing.T) {
	rec := &recorder{
		status: 409,
		body:   `{"error":{"code":"SESSION_SUSPENDED","message":"this number is disconnected","detail":{"resolve":"POST /v1/billing/checkout"}}}`,
	}
	client := newTest(rec)

	_, err := client.Messages.Send(context.Background(), "sess_1", M{"type": "text"}, "k")
	if err == nil {
		t.Fatal("expected an error")
	}
	// Callers branch on the code, never the message — the message gets reworded,
	// the code is a contract.
	if CodeOf(err) != "SESSION_SUSPENDED" {
		t.Errorf("CodeOf = %q", CodeOf(err))
	}
	if StatusOf(err) != 409 {
		t.Errorf("StatusOf = %d", StatusOf(err))
	}
}

func TestUnparseableErrorBodyStillFails(t *testing.T) {
	rec := &recorder{status: 502, body: "<html>bad gateway</html>"}
	client := newTest(rec)

	_, err := client.Sessions.List(context.Background())
	if err == nil {
		t.Fatal("a 502 must be an error even when the body is not JSON")
	}
	if StatusOf(err) != 502 {
		t.Errorf("StatusOf = %d", StatusOf(err))
	}
	// It must not invent a code that callers might branch on.
	if CodeOf(err) != "" {
		t.Errorf("invented a code: %q", CodeOf(err))
	}
}

// A send whose outcome is unknown must never be reported as safe to repeat.
// Retrying blind sends a real person the same message twice, which is worse
// than the original failure.
func TestRetryableIsConservative(t *testing.T) {
	rec := &recorder{status: 429, body: `{"error":{"code":"RATE_LIMITED","message":"slow down"}}`}
	_, err := newTest(rec).Sessions.List(context.Background())
	if !Retryable(err) {
		t.Error("429 should be retryable")
	}

	rec = &recorder{status: 422, body: `{"error":{"code":"VALIDATION","message":"bad body"}}`}
	_, err = newTest(rec).Sessions.List(context.Background())
	if Retryable(err) {
		t.Error("422 must not be retryable — the request is wrong, not unlucky")
	}

	if Retryable(nil) {
		t.Error("nil is not retryable")
	}
}

func TestDeleteToleratesAnEmptyBody(t *testing.T) {
	rec := &recorder{status: 204, body: ""}
	client := newTest(rec)

	if err := client.Sessions.Delete(context.Background(), "sess_1"); err != nil {
		t.Fatalf("204 with no body should succeed: %v", err)
	}
}

// ---------------------------------------------------------------- webhooks

// hmacHex computes the signature by the documented rule, independently of the
// verifier. Reusing VerifyWebhook's own code to build the expectation would
// make these tests agree with themselves rather than with the spec.
func hmacHex(secret, payload string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(payload))
	return hex.EncodeToString(mac.Sum(nil))
}

func TestVerifyWebhookAcceptsAGenuineSignature(t *testing.T) {
	const secret = "whsec_test"
	body := `{"event":"message.status","data":{"id":"msg_1"}}`
	now := time.Unix(1_700_000_000, 0)

	// Produce a signature by the documented rule, independently of the verifier.
	expected := hmacHex(secret, strconv.FormatInt(now.Unix(), 10)+"."+body)

	res := VerifyWebhook(VerifyOptions{
		RawBody:   []byte(body),
		Signature: "v1,sha256=" + expected,
		Timestamp: strconv.FormatInt(now.Unix(), 10),
		Secret:    secret,
		Now:       now,
	})
	if !res.Valid {
		t.Fatalf("valid signature rejected: %s", res.Reason)
	}
}

func TestVerifyWebhookRejectsTamperedBody(t *testing.T) {
	const secret = "whsec_test"
	body := `{"event":"message.status"}`
	now := time.Unix(1_700_000_000, 0)
	sig := "v1,sha256=" + hmacHex(secret, strconv.FormatInt(now.Unix(), 10)+"."+body)

	res := VerifyWebhook(VerifyOptions{
		RawBody:   []byte(body + " "), // one byte different
		Signature: sig,
		Timestamp: strconv.FormatInt(now.Unix(), 10),
		Secret:    secret,
		Now:       now,
	})
	if res.Valid {
		t.Fatal("a tampered body was accepted")
	}
	if res.Reason != ReasonSignature {
		t.Errorf("reason = %s, want signature", res.Reason)
	}
}

// The replay window has to bound both directions: a timestamp from the future
// is as suspicious as a stale one.
func TestVerifyWebhookRejectsStaleAndFutureTimestamps(t *testing.T) {
	const secret = "whsec_test"
	body := `{}`
	now := time.Unix(1_700_000_000, 0)

	for _, skew := range []int64{-600, 600} {
		ts := now.Unix() + skew
		sig := "v1,sha256=" + hmacHex(secret, strconv.FormatInt(ts, 10)+"."+body)
		res := VerifyWebhook(VerifyOptions{
			RawBody: []byte(body), Signature: sig,
			Timestamp: strconv.FormatInt(ts, 10), Secret: secret, Now: now,
		})
		if res.Valid {
			t.Errorf("accepted a signature %d seconds out", skew)
		}
		if res.Reason != ReasonTimestamp {
			t.Errorf("skew %d: reason = %s, want timestamp", skew, res.Reason)
		}
	}
}

func TestVerifyWebhookRejectsMalformedHeaders(t *testing.T) {
	cases := []struct {
		name string
		opts VerifyOptions
		want VerifyReason
	}{
		{"missing signature", VerifyOptions{RawBody: []byte("{}"), Timestamp: "1700000000"}, ReasonFormat},
		{"wrong scheme", VerifyOptions{RawBody: []byte("{}"), Signature: "v2,sha256=abc", Timestamp: "1700000000"}, ReasonFormat},
		{"missing timestamp", VerifyOptions{RawBody: []byte("{}"), Signature: "v1,sha256=abc"}, ReasonTimestamp},
		{"non-numeric timestamp", VerifyOptions{RawBody: []byte("{}"), Signature: "v1,sha256=abc", Timestamp: "yesterday"}, ReasonTimestamp},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			res := VerifyWebhook(tc.opts)
			if res.Valid {
				t.Fatal("accepted a malformed webhook")
			}
			if res.Reason != tc.want {
				t.Errorf("reason = %s, want %s", res.Reason, tc.want)
			}
		})
	}
}

// The retired proxy endpoint must not come back. It was removed from the API
// with D44/D46, and three SDKs shipped a method that called it and 404'd.
func TestNoProxyEndpointRemains(t *testing.T) {
	rec := &recorder{}
	client := newTest(rec)
	_, _ = client.Sessions.Get(context.Background(), "sess_1")
	if strings.Contains(rec.last.URL.Path, "proxy") {
		t.Error("a proxy path is being called")
	}
}

func TestResponseWithoutEnvelopeStillDecodes(t *testing.T) {
	rec := &recorder{body: `{"ok":true,"count":3}`}
	client := newTest(rec)

	out, err := client.Sessions.Get(context.Background(), "sess_1")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if out["ok"] != true {
		t.Errorf("un-enveloped body should fall through whole, got %v", out)
	}
}

func TestJSONBodyIsSentForWrites(t *testing.T) {
	rec := &recorder{}
	client := newTest(rec)

	_, _ = client.Sessions.Create(context.Background(), "LK", "support line")

	var sent M
	if err := json.Unmarshal([]byte(rec.lastBody), &sent); err != nil {
		t.Fatalf("body was not JSON: %q", rec.lastBody)
	}
	if sent["country"] != "LK" || sent["label"] != "support line" {
		t.Errorf("body = %v", sent)
	}
	if ct := rec.last.Header.Get("Content-Type"); ct != "application/json" {
		t.Errorf("Content-Type = %q", ct)
	}
}
