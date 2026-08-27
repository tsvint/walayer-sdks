package walayer

import (
	"encoding/json"
	"errors"
	"fmt"
)

// Error is a typed API failure.
//
// Code is the stable machine-readable string from packages/contracts errors —
// SESSION_SUSPENDED, RECIPIENT_SUPPRESSED, QUOTA_EXCEEDED and so on. Branch on
// Code, never on Message: the message is written for a human and will be
// reworded, the code is a contract.
type Error struct {
	// HTTP status.
	StatusCode int
	// Stable error code, e.g. "SESSION_NOT_FOUND".
	Code string
	// Human-readable, and safe to show a customer.
	Message string
	// Whatever else the API attached — validation issues, a resolve hint.
	Detail map[string]any
	// The raw body, for logging a shape this client did not expect.
	Raw string
}

func (e *Error) Error() string {
	if e.Code != "" {
		return fmt.Sprintf("walayer: %s (%d): %s", e.Code, e.StatusCode, e.Message)
	}
	return fmt.Sprintf("walayer: HTTP %d: %s", e.StatusCode, e.Message)
}

// CodeOf returns the API error code from err, or "" if it is not an *Error.
//
//	if walayer.CodeOf(err) == "RECIPIENT_SUPPRESSED" { ... }
func CodeOf(err error) string {
	var apiErr *Error
	if errors.As(err, &apiErr) {
		return apiErr.Code
	}
	return ""
}

// StatusOf returns the HTTP status from err, or 0 if it is not an *Error.
func StatusOf(err error) int {
	var apiErr *Error
	if errors.As(err, &apiErr) {
		return apiErr.StatusCode
	}
	return 0
}

// Retryable reports whether retrying err could plausibly succeed.
//
// Deliberately conservative, and deliberately NOT true for a send whose outcome
// is unknown. A 5xx on a send means the request may have landed; retrying it
// blind sends a real person the same WhatsApp message twice, which is worse
// than the failure. Use this for reads, and reconcile writes.
func Retryable(err error) bool {
	var apiErr *Error
	if !errors.As(err, &apiErr) {
		// Transport failure with no response. The caller knows whether the
		// operation was safe to repeat; this package does not.
		return false
	}
	return apiErr.StatusCode == 429 || apiErr.StatusCode >= 500
}

func parseError(status int, raw []byte) *Error {
	out := &Error{StatusCode: status, Raw: string(raw)}

	var body struct {
		Error struct {
			Code    string         `json:"code"`
			Message string         `json:"message"`
			Detail  map[string]any `json:"detail"`
		} `json:"error"`
	}
	if err := json.Unmarshal(raw, &body); err == nil && body.Error.Message != "" {
		out.Code = body.Error.Code
		out.Message = body.Error.Message
		out.Detail = body.Error.Detail
		return out
	}

	// A body we could not parse is still a failure; say so with what we have
	// rather than inventing a code that callers might branch on.
	out.Message = string(raw)
	if out.Message == "" {
		out.Message = "request failed"
	}
	return out
}
