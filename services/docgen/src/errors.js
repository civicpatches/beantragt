// Error type for every failure that leaves the service as JSON.
//
// `code` is a stable machine-readable identifier that Drupal maps to its own
// messages. `message` is a short English operator text. Neither may contain
// document content or render data.

export class DocgenError extends Error {
  /**
   * @param {number} status HTTP status code.
   * @param {string} code Stable error code.
   * @param {string} message Operator-facing message without document content.
   * @param {object} [details] Optional structured details (no document content).
   */
  constructor(status, code, message, details = undefined) {
    super(message);
    this.name = 'DocgenError';
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

export function badRequest(code, message, details) {
  return new DocgenError(400, code, message, details);
}

export function unprocessable(code, message, details) {
  return new DocgenError(422, code, message, details);
}

export function tooLarge(code, message, details) {
  return new DocgenError(413, code, message, details);
}

export function upstreamFailure(code, message, details) {
  return new DocgenError(502, code, message, details);
}

/**
 * Load shedding: the caller may retry after `retryAfterSeconds` (sent as the
 * Retry-After header). 429 for a tenant's own limit, 503 for the global one.
 */
export function overloaded(status, code, message, retryAfterSeconds) {
  const error = new DocgenError(status, code, message);
  error.retryAfterSeconds = retryAfterSeconds;
  return error;
}
