// Runtime configuration from the environment.
//
// Secrets may be given directly (DOCGEN_TOKEN, GOTENBERG_PASSWORD) or as a
// file path (DOCGEN_TOKEN_FILE, GOTENBERG_PASSWORD_FILE) so Docker/Swarm
// secrets work without putting values into the environment. The file variant
// wins when both are set.
//
// Callers (tenants) come from exactly one of two sources:
// - DOCGEN_TOKENS_FILE: the token table of the shared server service. One
//   line per tenant, `<tenant> <sha256 of the token in hex>`; blank lines and
//   lines starting with # are ignored. docgen never sees the plain tokens.
// - DOCGEN_TOKEN / DOCGEN_TOKEN_FILE: a single token for local DDEV and
//   on-premises installations with one tenant, named by DOCGEN_TENANT
//   (default "default").
// Setting both is a configuration error.

import { readFileSync } from 'node:fs';
import { sha256Digest } from './auth.js';

const MIB = 1024 * 1024;

export const DEFAULT_LIMITS = Object.freeze({
  // Template (POST /inspect, /render) and draft (POST /finalize) DOCX size.
  maxDocxBytes: 5 * MIB,
  // Sum of all uncompressed ZIP entries of one DOCX.
  maxUncompressedBytes: 50 * MIB,
  // Sum of all XML parts (any entry whose bytes start like XML, plus *.xml
  // and *.rels). XML is parsed into a DOM, which costs 60 to 200 times the
  // text size; binary media may use the rest of maxUncompressedBytes.
  maxXmlBytes: 4 * MIB,
  maxZipEntries: 2000,
  // Per-entry compression ratio (uncompressed / compressed). Ordinary Word
  // XML compresses 5 to 30 times; the check applies above a small floor so
  // tiny, highly repetitive parts do not trip it.
  maxCompressionRatio: 100,
  compressionRatioFloorBytes: 1 * MIB,
  // Rendered DOCX must still be a valid draft for /finalize.
  maxRenderedDocxBytes: 5 * MIB,
  // PDF returned by the converter.
  maxPdfBytes: 20 * MIB,
  // Request body: base64 of a 5 MB DOCX plus render data.
  maxRequestBytes: 12 * MIB,
});

// Parsing and templating run in a worker thread with its own heap limit, so a
// document that is too complex ends the worker, not the service. These jobs
// need no LibreOffice and may run in parallel (DOCGEN_MAX_CONCURRENT_WORKERS).
export const DEFAULT_JOBS = Object.freeze({
  heapMb: 256,
  timeoutMs: 30_000,
  maxConcurrent: 2,
  // Each waiting call holds its body (up to about 12 MB); see the memory
  // budget in deploy/docker-compose.docgen.yml.
  maxQueued: 8,
});

// Conversions of /finalize (Gotenberg plus veraPDF). One LibreOffice converts
// one document at a time, so the useful concurrency equals the number of
// Gotenberg replicas; the compose file couples DOCGEN_MAX_CONCURRENT_JOBS to
// DOCGEN_GOTENBERG_REPLICAS.
export const DEFAULT_FINALIZE = Object.freeze({
  maxConcurrent: 1,
  maxQueued: 8,
});

// Per tenant, over all routes: running calls and waiting calls. Above that a
// tenant gets 429 while other tenants keep being served.
export const DEFAULT_TENANT_LIMITS = Object.freeze({
  maxConcurrent: 2,
  maxQueued: 4,
});

// Tenant identifiers end up in logs and in the network name
// docgen-api-<tenant>; lowercase letters, digits and inner hyphens only.
export const TENANT_ID = /^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/u;
const DIGEST_HEX = /^[a-f0-9]{64}$/u;

function readSecret(env, name) {
  const filePath = env[`${name}_FILE`];
  if (filePath) {
    return readFileSync(filePath, 'utf8').trim();
  }
  return (env[name] ?? '').trim();
}

function integer(env, name, fallback, minimum) {
  const raw = env[name];
  if (raw === undefined || raw === '') {
    return fallback;
  }
  const value = Number(raw);
  if (!Number.isSafeInteger(value) || value < minimum) {
    throw new Error(minimum > 0 ? `${name} must be a positive integer.` : `${name} must be a non-negative integer.`);
  }
  return value;
}

function positiveInteger(env, name, fallback) {
  return integer(env, name, fallback, 1);
}

function nonNegativeInteger(env, name, fallback) {
  return integer(env, name, fallback, 0);
}

/**
 * Parses the token table. Throws on any malformed line, duplicate tenant or
 * duplicate token hash, so a broken table stops docgen at start.
 */
export function parseTokenTable(text) {
  const tenants = [];
  const ids = new Set();
  const digests = new Set();
  text.split(/\r?\n/u).forEach((rawLine, index) => {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) {
      return;
    }
    const parts = line.split(/\s+/u);
    if (parts.length !== 2 || !TENANT_ID.test(parts[0]) || !DIGEST_HEX.test(parts[1])) {
      // No line content in the message: a wrongly pasted plain token must not
      // end up in the container log.
      throw new Error(`DOCGEN_TOKENS_FILE line ${index + 1} must read "<tenant> <sha256 hex>" (tenant: a-z, 0-9, inner hyphens, at most 32 characters).`);
    }
    const [id, hex] = parts;
    if (ids.has(id)) {
      throw new Error(`DOCGEN_TOKENS_FILE lists tenant "${id}" twice.`);
    }
    if (digests.has(hex)) {
      throw new Error(`DOCGEN_TOKENS_FILE line ${index + 1} repeats the token hash of another tenant.`);
    }
    ids.add(id);
    digests.add(hex);
    tenants.push(Object.freeze({ id, digest: Buffer.from(hex, 'hex') }));
  });
  if (tenants.length === 0) {
    throw new Error('DOCGEN_TOKENS_FILE lists no tenant.');
  }
  return tenants;
}

function loadTenants(env) {
  const tableFile = (env.DOCGEN_TOKENS_FILE ?? '').trim();
  const singleToken = readSecret(env, 'DOCGEN_TOKEN');
  if (tableFile && singleToken) {
    throw new Error('Set either DOCGEN_TOKENS_FILE (token table) or DOCGEN_TOKEN/DOCGEN_TOKEN_FILE (single tenant), not both.');
  }
  if (tableFile) {
    return parseTokenTable(readFileSync(tableFile, 'utf8'));
  }
  if (singleToken.length < 32) {
    throw new Error('DOCGEN_TOKENS_FILE, or DOCGEN_TOKEN (or DOCGEN_TOKEN_FILE) with at least 32 characters, is required.');
  }
  const id = (env.DOCGEN_TENANT || 'default').trim();
  if (!TENANT_ID.test(id)) {
    throw new Error('DOCGEN_TENANT must use a-z, 0-9 and inner hyphens, at most 32 characters.');
  }
  return [Object.freeze({ id, digest: sha256Digest(singleToken) })];
}

function baseUrl(env, name, fallback) {
  // An empty value counts as unset (launch scripts pass every name through).
  const raw = (env[name] || fallback).trim();
  const url = new URL(raw);
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new Error(`${name} must be an http(s) URL.`);
  }
  if (url.username || url.password || url.search || url.hash) {
    throw new Error(`${name} must not contain credentials, query or fragment.`);
  }
  return url.toString().replace(/\/+$/u, '');
}

/**
 * Builds the service configuration. Throws on unsafe or missing settings so a
 * misconfigured container fails at start, not on the first request.
 */
export function loadConfig(env = process.env) {
  const tenants = loadTenants(env);

  const gotenbergUsername = (env.GOTENBERG_USERNAME ?? '').trim();
  const gotenbergPassword = readSecret(env, 'GOTENBERG_PASSWORD');
  if (!gotenbergUsername || gotenbergPassword.length < 16) {
    throw new Error('GOTENBERG_USERNAME and GOTENBERG_PASSWORD (at least 16 characters) are required.');
  }

  const limits = {
    ...DEFAULT_LIMITS,
    maxDocxBytes: positiveInteger(env, 'DOCGEN_MAX_DOCX_BYTES', DEFAULT_LIMITS.maxDocxBytes),
    maxPdfBytes: positiveInteger(env, 'DOCGEN_MAX_PDF_BYTES', DEFAULT_LIMITS.maxPdfBytes),
  };
  limits.maxXmlBytes = positiveInteger(env, 'DOCGEN_MAX_XML_BYTES', DEFAULT_LIMITS.maxXmlBytes);
  limits.maxRenderedDocxBytes = limits.maxDocxBytes;
  limits.maxRequestBytes = Math.max(
    DEFAULT_LIMITS.maxRequestBytes,
    Math.ceil(limits.maxDocxBytes * 4 / 3) + 4 * MIB,
  );

  return Object.freeze({
    host: (env.DOCGEN_HOST ?? '0.0.0.0').trim(),
    port: positiveInteger(env, 'DOCGEN_PORT', 3000),
    tenants: Object.freeze(tenants),
    gotenberg: Object.freeze({
      baseUrl: baseUrl(env, 'GOTENBERG_URL', 'http://docgen-gotenberg:3000'),
      username: gotenbergUsername,
      password: gotenbergPassword,
      // Above Gotenberg's own --api-timeout (60 s) so its error answer arrives.
      timeoutMs: positiveInteger(env, 'GOTENBERG_TIMEOUT_MS', 70_000),
    }),
    verapdf: Object.freeze({
      baseUrl: baseUrl(env, 'VERAPDF_URL', 'http://docgen-verapdf:8080'),
      timeoutMs: positiveInteger(env, 'VERAPDF_TIMEOUT_MS', 120_000),
    }),
    healthTimeoutMs: positiveInteger(env, 'DOCGEN_HEALTH_TIMEOUT_MS', 5_000),
    // GET /health needs no token; the upstream probe result is reused for
    // this long so the route cannot be used to hammer Gotenberg and veraPDF.
    healthCacheMs: nonNegativeInteger(env, 'DOCGEN_HEALTH_CACHE_MS', 5_000),
    retryAfterSeconds: positiveInteger(env, 'DOCGEN_RETRY_AFTER_SECONDS', 5),
    jobs: Object.freeze({
      heapMb: positiveInteger(env, 'DOCGEN_JOB_HEAP_MB', DEFAULT_JOBS.heapMb),
      timeoutMs: positiveInteger(env, 'DOCGEN_JOB_TIMEOUT_MS', DEFAULT_JOBS.timeoutMs),
      maxConcurrent: positiveInteger(env, 'DOCGEN_MAX_CONCURRENT_WORKERS', DEFAULT_JOBS.maxConcurrent),
      maxQueued: nonNegativeInteger(env, 'DOCGEN_MAX_QUEUED_WORKERS', DEFAULT_JOBS.maxQueued),
    }),
    finalize: Object.freeze({
      maxConcurrent: positiveInteger(env, 'DOCGEN_MAX_CONCURRENT_JOBS', DEFAULT_FINALIZE.maxConcurrent),
      maxQueued: nonNegativeInteger(env, 'DOCGEN_MAX_QUEUED_JOBS', DEFAULT_FINALIZE.maxQueued),
    }),
    tenantLimits: Object.freeze({
      maxConcurrent: positiveInteger(env, 'DOCGEN_TENANT_MAX_CONCURRENT', DEFAULT_TENANT_LIMITS.maxConcurrent),
      maxQueued: nonNegativeInteger(env, 'DOCGEN_TENANT_MAX_QUEUED', DEFAULT_TENANT_LIMITS.maxQueued),
    }),
    limits: Object.freeze(limits),
  });
}
