// Public shapes the SDK exposes. Kept intentionally small and stable — the wire
// surface is documented in the OpenAPI spec; these cover the common paths.

export interface Session {
  id: string;
  label: string | null;
  country: string;
  status: string;
  warmup_stage: number;
}

export interface SendResult {
  id: string;
  status: string;
  replay?: boolean;
}

/** A send body. `type` + `body` mirror the REST contract's 17 message types. */
export interface SendInput {
  type: string;
  to: string;
  body: Record<string, unknown>;
  options?: { schedule_at?: number };
}

export interface Webhook {
  id: string;
  url: string;
  events: string[];
  status: string;
}

export interface Suppression {
  phone: string;
  reason: string;
  created_at: number;
}
