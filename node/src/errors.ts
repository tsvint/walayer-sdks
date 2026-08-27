/** Error thrown for any non-2xx API response. Carries the WALayer error code. */
export class WALayerError extends Error {
  readonly status: number;
  readonly code: string;
  readonly detail: unknown;
  readonly requestId: string | null;

  constructor(status: number, code: string, message: string, detail: unknown, requestId: string | null) {
    super(message);
    this.name = "WALayerError";
    this.status = status;
    this.code = code;
    this.detail = detail;
    this.requestId = requestId;
  }
}
