package walayer

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// Webhook signature scheme, matching docs/04 §8.3 and the other SDKs:
//
//	X-Signature: v1,sha256=<hex>
//	hex = HMAC-SHA256(secret, "{timestamp}.{rawBody}")
const (
	signatureScheme         = "v1"
	SignatureHeader         = "X-Signature"
	TimestampHeader         = "X-Timestamp"
	DefaultToleranceSeconds = 300
)

// VerifyReason says why verification failed, for logging.
type VerifyReason string

const (
	ReasonFormat    VerifyReason = "format"
	ReasonTimestamp VerifyReason = "timestamp"
	ReasonSignature VerifyReason = "signature"
)

// VerifyResult is the outcome of checking a webhook.
type VerifyResult struct {
	Valid  bool
	Reason VerifyReason
}

// VerifyOptions are the inputs to VerifyWebhook.
type VerifyOptions struct {
	// RawBody must be the bytes exactly as received. Re-serialized JSON breaks
	// the HMAC — key order and whitespace are part of what was signed. This is
	// the single most common way webhook verification is got wrong.
	RawBody []byte
	// Signature is the X-Signature header value.
	Signature string
	// Timestamp is the X-Timestamp header value, in unix seconds.
	Timestamp string
	Secret    string
	// ToleranceSeconds bounds the replay window. Zero means the default.
	ToleranceSeconds int
	// Now is injectable for tests. Zero means time.Now().
	Now time.Time
}

// VerifyWebhook checks a WALayer webhook signature in constant time, with a
// replay window.
//
// It returns a result rather than an error so the caller decides the HTTP
// response, and so the failure reason can be logged without being leaked:
//
//	if !walayer.VerifyWebhook(opts).Valid {
//	    w.WriteHeader(http.StatusBadRequest)
//	    return
//	}
func VerifyWebhook(opts VerifyOptions) VerifyResult {
	prefix := signatureScheme + ",sha256="
	if opts.Signature == "" || !strings.HasPrefix(opts.Signature, prefix) {
		return VerifyResult{Valid: false, Reason: ReasonFormat}
	}

	ts, err := strconv.ParseInt(strings.TrimSpace(opts.Timestamp), 10, 64)
	if err != nil || ts == 0 {
		return VerifyResult{Valid: false, Reason: ReasonTimestamp}
	}

	tolerance := opts.ToleranceSeconds
	if tolerance == 0 {
		tolerance = DefaultToleranceSeconds
	}
	now := opts.Now
	if now.IsZero() {
		now = time.Now()
	}
	// Absolute difference: a timestamp in the future is as suspicious as a
	// stale one, and clock skew cuts both ways.
	if delta := now.Unix() - ts; delta > int64(tolerance) || delta < -int64(tolerance) {
		return VerifyResult{Valid: false, Reason: ReasonTimestamp}
	}

	mac := hmac.New(sha256.New, []byte(opts.Secret))
	mac.Write([]byte(strconv.FormatInt(ts, 10)))
	mac.Write([]byte("."))
	mac.Write(opts.RawBody)
	expected := prefix + hex.EncodeToString(mac.Sum(nil))

	// hmac.Equal, not ==: string comparison short-circuits on the first
	// differing byte and leaks the prefix length through timing.
	if !hmac.Equal([]byte(opts.Signature), []byte(expected)) {
		return VerifyResult{Valid: false, Reason: ReasonSignature}
	}
	return VerifyResult{Valid: true}
}

// VerifyRequest is the common case: verify an *http.Request whose body has
// already been read into rawBody.
//
// The body is taken as a parameter rather than read here, because reading it
// consumes the stream and the caller almost always needs it afterwards.
func VerifyRequest(r *http.Request, rawBody []byte, secret string) VerifyResult {
	return VerifyWebhook(VerifyOptions{
		RawBody:   rawBody,
		Signature: r.Header.Get(SignatureHeader),
		Timestamp: r.Header.Get(TimestampHeader),
		Secret:    secret,
	})
}
