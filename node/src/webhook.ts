import { createHmac, timingSafeEqual } from "node:crypto";

// Must match the server scheme (docs/04 §8.3): X-Signature: v1,sha256=<hex>,
// hex = HMAC-SHA256(secret, "{timestamp}.{rawBody}"). Verify against the RAW
// body — re-serialized JSON breaks the HMAC.
const SIGNATURE_SCHEME = "v1";
const DEFAULT_TOLERANCE_SECONDS = 300;

export interface VerifyResult {
  valid: boolean;
  reason?: "format" | "timestamp" | "signature";
}

export interface VerifyOptions {
  /** the raw request body string, exactly as received */
  rawBody: string;
  /** value of the X-Signature header */
  signature: string | undefined;
  /** value of the X-Timestamp header (unix seconds) */
  timestamp: number | string | undefined;
  secret: string;
  toleranceSeconds?: number;
  nowSeconds?: number;
}

/**
 * Verify a WALayer webhook. Constant-time, with a replay window. Returns a
 * structured result so callers can log the reason; throwing is left to the
 * caller (`if (!verifyWebhook(...).valid) return res.status(400))`).
 */
export function verifyWebhook(opts: VerifyOptions): VerifyResult {
  const { rawBody, signature, secret } = opts;
  const tolerance = opts.toleranceSeconds ?? DEFAULT_TOLERANCE_SECONDS;
  const now = opts.nowSeconds ?? Math.floor(Date.now() / 1000);
  const ts = typeof opts.timestamp === "string" ? Number(opts.timestamp) : opts.timestamp;

  if (!signature || !signature.startsWith(`${SIGNATURE_SCHEME},sha256=`)) return { valid: false, reason: "format" };
  if (!ts || !Number.isFinite(ts) || Math.abs(now - ts) > tolerance) return { valid: false, reason: "timestamp" };

  const expected = `${SIGNATURE_SCHEME},sha256=${createHmac("sha256", secret).update(`${ts}.${rawBody}`).digest("hex")}`;
  const a = Buffer.from(signature);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !timingSafeEqual(a, b)) return { valid: false, reason: "signature" };
  return { valid: true };
}
