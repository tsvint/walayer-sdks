// Package walayer is the Go client for the WALayer WhatsApp API.
//
// It mirrors the TypeScript, Python and PHP SDKs: the same resources, the same
// method names in Go casing, and the same webhook signature scheme. A team
// running two of these should be able to read one and predict the other.
//
// Zero dependencies outside the standard library, deliberately. This is a thin
// JSON client; pulling a HTTP or JSON package into every caller's build to save
// a hundred lines here is a bad trade for a library.
//
//	client := walayer.New("wal_live_...")
//	res, err := client.Messages.Send(ctx, "sess_123", walayer.M{
//	    "type": "text", "to": "+94771234567", "body": "hello",
//	}, "order-4417")
package walayer

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// DefaultBaseURL matches the other SDKs.
const DefaultBaseURL = "https://api.walayer.com"

// M is a convenience alias for the loosely-typed request and response bodies.
//
// The API surface is wide and still moving, and a hand-written struct per
// message type would be stale within a release. Callers who want types can
// unmarshal a Raw response into their own.
type M = map[string]any

// Doer is the subset of *http.Client this package needs.
//
// Injectable so tests need no network and callers can supply their own
// transport — the same shape as the Python SDK's transport hook.
type Doer interface {
	Do(req *http.Request) (*http.Response, error)
}

// Client is the entry point. Create one with New and share it; it is safe for
// concurrent use by multiple goroutines.
type Client struct {
	http *httpClient

	Sessions     *Sessions
	Messages     *Messages
	Inbox        *Inbox
	Media        *Media
	Channels     *Channels
	Webhooks     *Webhooks
	Suppressions *Suppressions
}

// Option configures a Client.
type Option func(*httpClient)

// WithBaseURL points the client at a different host.
func WithBaseURL(base string) Option {
	return func(h *httpClient) { h.baseURL = strings.TrimRight(base, "/") }
}

// WithHTTPClient supplies the HTTP doer, for tests or custom transports.
func WithHTTPClient(d Doer) Option {
	return func(h *httpClient) { h.doer = d }
}

// New returns a Client authenticating with the given API key.
//
// The key is not validated here beyond being non-empty; an invalid one surfaces
// as an *Error with StatusCode 401 on the first call, which is where a caller
// can actually do something about it.
func New(apiKey string, opts ...Option) *Client {
	h := &httpClient{
		apiKey:  apiKey,
		baseURL: DefaultBaseURL,
		doer:    &http.Client{Timeout: 30 * time.Second},
	}
	for _, opt := range opts {
		opt(h)
	}

	c := &Client{http: h}
	c.Sessions = &Sessions{http: h}
	c.Messages = &Messages{http: h}
	c.Inbox = &Inbox{http: h}
	c.Media = &Media{http: h}
	c.Channels = &Channels{http: h}
	c.Webhooks = &Webhooks{http: h}
	c.Suppressions = &Suppressions{http: h}
	return c
}

// ---------------------------------------------------------------- transport

type httpClient struct {
	apiKey  string
	baseURL string
	doer    Doer
}

// request performs one call and unwraps the API's {data: ...} envelope.
//
// headers is used for Idempotency-Key, which is not optional on sends: the API
// requires it, and a client that omitted it would turn a network retry into a
// duplicate WhatsApp message to a real person.
func (h *httpClient) request(ctx context.Context, path, method string, body any, query url.Values, headers map[string]string) (json.RawMessage, error) {
	if h.apiKey == "" {
		return nil, fmt.Errorf("walayer: api key is required")
	}

	var reader io.Reader
	if body != nil {
		encoded, err := json.Marshal(body)
		if err != nil {
			return nil, fmt.Errorf("walayer: encoding request body: %w", err)
		}
		reader = bytes.NewReader(encoded)
	}

	endpoint := h.baseURL + path
	if len(query) > 0 {
		endpoint += "?" + query.Encode()
	}

	req, err := http.NewRequestWithContext(ctx, method, endpoint, reader)
	if err != nil {
		return nil, fmt.Errorf("walayer: building request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+h.apiKey)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	for k, v := range headers {
		if v != "" {
			req.Header.Set(k, v)
		}
	}

	res, err := h.doer.Do(req)
	if err != nil {
		return nil, fmt.Errorf("walayer: %s %s: %w", method, path, err)
	}
	defer res.Body.Close()

	raw, err := io.ReadAll(res.Body)
	if err != nil {
		return nil, fmt.Errorf("walayer: reading response: %w", err)
	}

	if res.StatusCode < 200 || res.StatusCode >= 300 {
		return nil, parseError(res.StatusCode, raw)
	}

	// 204 and empty bodies are normal for deletes.
	if len(bytes.TrimSpace(raw)) == 0 {
		return nil, nil
	}

	var envelope struct {
		Data json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal(raw, &envelope); err != nil {
		return nil, fmt.Errorf("walayer: decoding response: %w", err)
	}
	// Not every endpoint wraps in {data}. Fall back to the whole body rather
	// than returning nil and making the caller guess which shape they got.
	if envelope.Data == nil {
		return raw, nil
	}
	return envelope.Data, nil
}

// into runs a request and unmarshals the unwrapped payload into out.
func (h *httpClient) into(ctx context.Context, path, method string, body any, query url.Values, headers map[string]string, out any) error {
	raw, err := h.request(ctx, path, method, body, query, headers)
	if err != nil {
		return err
	}
	if out == nil || raw == nil {
		return nil
	}
	if err := json.Unmarshal(raw, out); err != nil {
		return fmt.Errorf("walayer: decoding %s %s: %w", method, path, err)
	}
	return nil
}

// seg escapes one path segment. Session ids and JIDs contain characters that
// change the route if pasted in raw.
func seg(s string) string { return url.PathEscape(s) }

// query builds a query string, dropping unset values so callers can pass
// optional filters through without branching at every call site.
func query(pairs map[string]any) url.Values {
	v := url.Values{}
	for key, value := range pairs {
		switch typed := value.(type) {
		case nil:
			continue
		case string:
			if typed != "" {
				v.Set(key, typed)
			}
		case *string:
			if typed != nil && *typed != "" {
				v.Set(key, *typed)
			}
		case bool:
			v.Set(key, fmt.Sprintf("%t", typed))
		case *bool:
			if typed != nil {
				v.Set(key, fmt.Sprintf("%t", *typed))
			}
		case int:
			if typed != 0 {
				v.Set(key, fmt.Sprintf("%d", typed))
			}
		default:
			v.Set(key, fmt.Sprintf("%v", typed))
		}
	}
	return v
}
