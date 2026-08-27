import { WALayerError } from "./errors.js";

export interface HttpOptions {
  apiKey: string;
  baseUrl?: string;
  /** injectable for tests; defaults to global fetch */
  fetch?: typeof fetch;
}

interface RequestInitEx {
  method?: string;
  body?: unknown;
  headers?: Record<string, string>;
  /** appended as a query string; undefined and null values are dropped */
  query?: Record<string, string | number | boolean | undefined | null>;
}

/** A list response that carries paging state alongside the rows. */
export interface Page<T> {
  data: T[];
  next_cursor: string | null;
}

/**
 * Thin JSON transport over the WALayer REST API. Adds the Bearer key, unwraps
 * the `{ data }` envelope, and turns any non-2xx into a typed WALayerError with
 * the server's error code and request id — so callers `catch (e) { e.code }`
 * rather than parsing bodies.
 */
export class Http {
  private readonly apiKey: string;
  private readonly baseUrl: string;
  private readonly doFetch: typeof fetch;

  constructor(opts: HttpOptions) {
    if (!opts.apiKey) throw new Error("WALayer: apiKey is required");
    this.apiKey = opts.apiKey;
    this.baseUrl = (opts.baseUrl ?? "https://api.walayer.com").replace(/\/$/, "");
    this.doFetch = opts.fetch ?? globalThis.fetch;
    if (!this.doFetch) throw new Error("WALayer: no fetch available; pass one via options.fetch");
  }

  /** Build `?a=1&b=2`, dropping anything unset so callers can spread options. */
  private qs(query: RequestInitEx["query"]): string {
    if (!query) return "";
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== "") p.set(k, String(v));
    }
    const s = p.toString();
    return s ? `?${s}` : "";
  }

  /**
   * The full response envelope, not just `data`.
   *
   * List endpoints return `{ data, next_cursor }` and `/v1/search` returns
   * `{ data, meta }`. `request()` unwraps to `data`, which silently threw the
   * cursor away — so an SDK user could read the first page and had no way to
   * ask for the second.
   */
  async envelope<T>(path: string, init: RequestInitEx = {}): Promise<T> {
    return this.send<T>(path, init, false);
  }

  async request<T>(path: string, init: RequestInitEx = {}): Promise<T> {
    return this.send<T>(path, init, true);
  }

  private async send<T>(path: string, init: RequestInitEx, unwrap: boolean): Promise<T> {
    const headers: Record<string, string> = {
      authorization: `Bearer ${this.apiKey}`,
      ...(init.headers ?? {}),
    };
    if (init.body !== undefined) headers["content-type"] = "application/json";

    const reqInit: RequestInit = { method: init.method ?? "GET", headers };
    if (init.body !== undefined) reqInit.body = JSON.stringify(init.body);
    const res = await this.doFetch(`${this.baseUrl}${path}${this.qs(init.query)}`, reqInit);

    const requestId = res.headers.get("x-request-id");
    if (res.status === 204) return undefined as T;

    const text = await res.text();
    const json = text ? (JSON.parse(text) as unknown) : {};

    if (!res.ok) {
      const err = (json as { error?: { code?: string; message?: string; detail?: unknown } }).error ?? {};
      throw new WALayerError(res.status, err.code ?? "UNKNOWN", err.message ?? res.statusText, err.detail, requestId);
    }
    return unwrap ? (json as { data: T }).data : (json as T);
  }
}
