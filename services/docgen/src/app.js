// HTTP API of the docgen service. JSON in, JSON out, nothing is stored.
//
//   GET  /health   -> { status, gotenberg, verapdf }
//   POST /inspect  { template }        -> { placeholders, loops, errors }
//   POST /render   { template, data }  -> { docx, sha256, missing, sanitized }
//   POST /finalize { docx }            -> { pdf, sha256, docxSha256, validation, sanitized }
//
// `sanitized.removedExternalRelationships` is an additive field: the number of
// external relationships docgen removed from the received DOCX before use.
// DOCX checks and templating run in worker threads (jobs.js).
//
// docgen is one shared service per server. Every route except GET /health
// requires `Authorization: Bearer <token>`; the token identifies the tenant
// (token table, auth.js). The tenant is used for logs and per-tenant limits
// only. GET /health needs no token and lists no tenants.
//
// Load limits, from the outside in:
// 1. per tenant (DOCGEN_TENANT_MAX_CONCURRENT running, DOCGEN_TENANT_MAX_QUEUED
//    waiting, over all routes): beyond that 429 `tenant_busy` with
//    Retry-After, while other tenants keep being served;
// 2. global worker threads for DOCX checks and templating (jobs.js) and
//    global conversions of /finalize (DOCGEN_MAX_CONCURRENT_JOBS, coupled to
//    the number of Gotenberg replicas): beyond that 503 `docgen_busy` with
//    Retry-After. Both global pools are asked before the request body is
//    read, so a refused call never buffers its body (memory budget of the
//    single docgen task). A full Gotenberg queue
//    (Gotenberg 503) also ends as a retryable 503 `docgen_busy`.
//
// Every request gets a random request id (log lines, response header
// X-Request-Id, Gotenberg-Trace header towards Gotenberg), so a converter
// failure can be traced to a tenant. Documents and render data are never
// logged.

import { createHash, randomUUID } from 'node:crypto';
import { createTenantVerifier } from './auth.js';
import { DocgenError, badRequest, overloaded, tooLarge } from './errors.js';
import { createJobRunner } from './jobs.js';
import { createLimiter, createTenantLimiter } from './limiter.js';
import { convertToPdfA, upstreamHealth, validatePdfA } from './finalize.js';

// Byte classes of the base64 alphabet (RFC 4648, section 4).
const BASE64_ALPHABET = new Uint8Array(128);
for (const character of 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/') {
  BASE64_ALPHABET[character.charCodeAt(0)] = 1;
}

/**
 * Canonical padded base64 in one linear pass: length a multiple of 4, only
 * alphabet characters, at most two '=' and only at the end. A regular
 * expression with a repeated group recurses per group in V8 and overflows
 * the stack on inputs of a few MB (RangeError), so no regex is used here.
 */
export function isCanonicalBase64(value) {
  const length = value.length;
  if (length === 0 || length % 4 !== 0) {
    return false;
  }
  let padding = 0;
  if (value.charCodeAt(length - 1) === 61) {
    padding = value.charCodeAt(length - 2) === 61 ? 2 : 1;
  }
  const end = length - padding;
  for (let index = 0; index < end; index++) {
    const code = value.charCodeAt(index);
    if (code >= 128 || BASE64_ALPHABET[code] !== 1) {
      return false;
    }
  }
  return true;
}

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

function sendJson(response, status, body, extraHeaders = {}) {
  const payload = JSON.stringify(body);
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(payload),
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
    ...extraHeaders,
  });
  response.end(payload);
}

function sendError(response, error) {
  const body = { error: error.code, message: error.message };
  if (error.details !== undefined) {
    body.details = error.details;
  }
  const headers = {};
  if (Number.isSafeInteger(error.retryAfterSeconds)) {
    headers['Retry-After'] = String(error.retryAfterSeconds);
  }
  sendJson(response, error.status, body, headers);
}

async function readJsonBody(request, maxBytes) {
  const contentType = String(request.headers['content-type'] ?? '');
  if (!/^application\/json(\s*;|$)/iu.test(contentType)) {
    throw new DocgenError(415, 'unsupported_media_type', 'Send the request body as application/json.');
  }
  const declared = Number(request.headers['content-length'] ?? '');
  if (Number.isFinite(declared) && declared > maxBytes) {
    throw tooLarge('request_too_large', 'The request body exceeds the allowed size.', { maxRequestBytes: maxBytes });
  }
  const chunks = [];
  let total = 0;
  for await (const chunk of request) {
    total += chunk.length;
    if (total > maxBytes) {
      throw tooLarge('request_too_large', 'The request body exceeds the allowed size.', { maxRequestBytes: maxBytes });
    }
    chunks.push(chunk);
  }
  let body;
  try {
    body = JSON.parse(Buffer.concat(chunks, total).toString('utf8'));
  } catch {
    throw badRequest('invalid_json', 'The request body is not valid JSON.');
  }
  if (body === null || typeof body !== 'object' || Array.isArray(body)) {
    throw badRequest('invalid_request', 'The request body must be a JSON object.');
  }
  return body;
}

function decodeDocxField(body, field, limits) {
  const value = body[field];
  if (typeof value !== 'string' || value.length === 0) {
    throw badRequest('invalid_request', `Field "${field}" must be a non-empty base64 string.`);
  }
  if (value.length > Math.ceil(limits.maxDocxBytes / 3) * 4) {
    throw tooLarge('docx_too_large', 'The DOCX exceeds the allowed size.', { maxDocxBytes: limits.maxDocxBytes });
  }
  if (!isCanonicalBase64(value)) {
    throw badRequest('invalid_base64', `Field "${field}" is not valid base64.`);
  }
  return Buffer.from(value, 'base64');
}

function requireDataObject(body) {
  const data = body.data;
  if (data === null || typeof data !== 'object' || Array.isArray(data)) {
    throw badRequest('invalid_request', 'Field "data" must be a JSON object.');
  }
  return data;
}

/**
 * Wraps the async request handler so that no failure of one request ever
 * becomes an unhandled rejection (which ends a Node 24 process and with it
 * the running jobs of every tenant). Whatever escapes is logged without
 * content and the connection is closed.
 *
 * @param {(request, response) => Promise<void>} handle
 * @param {(line: object) => void} log
 */
export function guardRequestHandler(handle, log) {
  return (request, response) => {
    let pending;
    try {
      pending = Promise.resolve(handle(request, response));
    } catch (error) {
      pending = Promise.reject(error);
    }
    pending.catch((error) => {
      try {
        log({ level: 'error', msg: 'request handler failed', error: error?.name ?? 'Error' });
      } catch {
        // Logging must not fail the guard.
      }
      try {
        if (!response.headersSent) {
          sendError(response, new DocgenError(500, 'internal_error', 'Internal error.'));
        } else {
          response.destroy();
        }
      } catch {
        response.destroy();
      }
    });
  };
}

/**
 * @param {object} config Result of loadConfig().
 * @param {object} [dependencies] Test seams.
 * @param {typeof fetch} [dependencies.fetch]
 * @param {(line: object) => void} [dependencies.log]
 * @param {Function} [dependencies.runJob]
 */
export function createApp(config, dependencies = {}) {
  const fetchImpl = dependencies.fetch ?? fetch;
  const log = dependencies.log ?? (line => process.stdout.write(`${JSON.stringify(line)}\n`));
  const runJob = dependencies.runJob ?? createJobRunner(config);
  const identifyTenant = createTenantVerifier(config.tenants);
  const { limits, retryAfterSeconds } = config;
  const tenantLimiter = createTenantLimiter(
    config.tenants.map(tenant => tenant.id),
    config.tenantLimits,
    () => overloaded(429, 'tenant_busy', 'Too many concurrent requests for this tenant, retry later.', retryAfterSeconds),
  );
  const busy = () => overloaded(503, 'docgen_busy', 'docgen is busy, retry later.', retryAfterSeconds);
  const conversions = createLimiter({ ...config.finalize, onFull: busy });
  // Load shedding before the body is buffered. The limiters still decide
  // when the job starts; this check only refuses calls that would be
  // refused anyway once their body is read.
  const workersAvailable = () => typeof runJob.hasCapacity !== 'function' || runJob.hasCapacity();
  const requireCapacity = (...pools) => {
    if (!pools.every(available => available())) {
      throw busy();
    }
  };

  // Upstream probes for the unauthenticated health route: concurrent callers
  // share one probe, and a result is reused for healthCacheMs.
  let healthCache = null;
  function cachedUpstreamHealth() {
    const now = Date.now();
    if (healthCache && (healthCache.pending || now - healthCache.at < config.healthCacheMs)) {
      return healthCache.promise;
    }
    const entry = { at: now, pending: true, promise: null };
    entry.promise = upstreamHealth(config, fetchImpl).finally(() => {
      entry.pending = false;
      entry.at = Date.now();
    });
    healthCache = entry;
    return entry.promise;
  }

  const health = async () => {
    const upstream = await cachedUpstreamHealth();
    const ok = upstream.gotenberg === 'ok' && upstream.verapdf === 'ok';
    return [ok ? 200 : 503, { status: ok ? 'ok' : 'degraded', ...upstream }];
  };

  const routes = {
    'POST /inspect': async (request) => {
      requireCapacity(workersAvailable);
      const body = await readJsonBody(request, limits.maxRequestBytes);
      const { result } = await runJob('inspect', decodeDocxField(body, 'template', limits));
      return [200, result];
    },
    'POST /render': async (request) => {
      requireCapacity(workersAvailable);
      const body = await readJsonBody(request, limits.maxRequestBytes);
      const template = decodeDocxField(body, 'template', limits);
      const data = requireDataObject(body);
      const { docx, missing, sanitized } = await runJob('render', template, data);
      return [200, { docx: docx.toString('base64'), sha256: sha256(docx), missing, sanitized }];
    },
    'POST /finalize': async (request, requestId) => {
      requireCapacity(workersAvailable, () => conversions.hasCapacity());
      const body = await readJsonBody(request, limits.maxRequestBytes);
      const received = decodeDocxField(body, 'docx', limits);
      const { docx: draft, sanitized } = await runJob('sanitize', received);
      // One slot per Gotenberg replica covers conversion and validation.
      const { pdf, validation } = await conversions.run(async () => {
        const converted = await convertToPdfA(draft, config, fetchImpl, { traceId: requestId });
        return { pdf: converted, validation: await validatePdfA(converted, config, fetchImpl) };
      });
      return [200, {
        pdf: pdf.toString('base64'),
        sha256: sha256(pdf),
        docxSha256: sha256(received),
        validation,
        sanitized,
      }];
    },
  };
  const knownPaths = new Set([...Object.keys(routes).map(key => key.split(' ')[1]), '/health']);

  return async function handle(request, response) {
    const started = process.hrtime.bigint();
    const requestId = randomUUID();
    response.setHeader('X-Request-Id', requestId);
    let status = 500;
    let tenant;
    let path = '(invalid)';
    try {
      // Inside the try: a request target like '//[' makes new URL() throw,
      // which outside it would end as an unhandled rejection.
      try {
        path = new URL(request.url ?? '/', 'http://docgen.invalid').pathname;
      } catch {
        throw badRequest('invalid_request_target', 'The request target is not a valid URL path.');
      }
      if (request.method === 'GET' && path === '/health') {
        const [routeStatus, body] = await health();
        status = routeStatus;
        sendJson(response, status, body);
        return;
      }
      tenant = identifyTenant(request.headers.authorization) ?? undefined;
      if (!tenant) {
        throw new DocgenError(401, 'unauthorized', 'A valid bearer token is required.');
      }
      const route = routes[`${request.method} ${path}`];
      if (!route) {
        throw knownPaths.has(path)
          ? new DocgenError(405, 'method_not_allowed', 'Method not allowed.')
          : new DocgenError(404, 'not_found', 'Not found.');
      }
      const [routeStatus, body] = await tenantLimiter.run(tenant, () => route(request, requestId));
      status = routeStatus;
      sendJson(response, status, body);
    } catch (error) {
      status = error instanceof DocgenError ? error.status : 500;
      if (!(error instanceof DocgenError)) {
        log({ level: 'error', msg: 'unhandled error', requestId, path, tenant, error: error?.name ?? 'Error' });
      }
      // The answer itself may fail (headers already sent, socket gone); that
      // must never escape the handler either.
      try {
        if (response.headersSent) {
          response.destroy();
        } else {
          sendError(response, error instanceof DocgenError ? error : new DocgenError(500, 'internal_error', 'Internal error.'));
        }
      } catch {
        response.destroy();
      }
    } finally {
      const durationMs = Number(process.hrtime.bigint() - started) / 1e6;
      log({ level: 'info', msg: 'request', requestId, method: request.method, path, tenant, status, durationMs: Math.round(durationMs) });
    }
  };
}
