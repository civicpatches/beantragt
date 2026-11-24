// PDF/A-2b creation (Gotenberg, LibreOffice route) and validation (veraPDF-rest).
//
// Both upstreams sit in an internal network that only docgen can reach.
// docgen never forwards URLs: Gotenberg receives the sanitized DOCX as a
// multipart upload, veraPDF receives the PDF as a multipart upload on
// /api/validate/2b. The URL-fetching veraPDF route (/api/validate/url/{id})
// must never be used.

import { overloaded, tooLarge, upstreamFailure } from './errors.js';

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
export const PDFA_PROFILE = '2b';
const GOTENBERG_PDFA = 'PDF/A-2b';
const MAX_REPORTED_CLAUSES = 5;

async function readCapped(response, maxBytes, code, message) {
  const declared = Number(response.headers.get('content-length') ?? '');
  if (Number.isFinite(declared) && declared > maxBytes) {
    await response.body?.cancel();
    throw tooLarge(code, message, { maxBytes });
  }
  if (!response.body) {
    return Buffer.alloc(0);
  }
  const chunks = [];
  let total = 0;
  for await (const chunk of response.body) {
    total += chunk.length;
    if (total > maxBytes) {
      throw tooLarge(code, message, { maxBytes });
    }
    chunks.push(chunk);
  }
  return Buffer.concat(chunks, total);
}

function basicAuth(username, password) {
  return `Basic ${Buffer.from(`${username}:${password}`, 'utf8').toString('base64')}`;
}

/**
 * Converts a sanitized DOCX into PDF/A-2b through Gotenberg.
 *
 * @param {object} [options]
 * @param {string} [options.traceId] docgen request id, sent as Gotenberg-Trace
 *   so Gotenberg's log lines carry the same id as docgen's.
 */
export async function convertToPdfA(docx, config, fetchImpl = fetch, options = {}) {
  const form = new FormData();
  form.append('files', new Blob([docx], { type: DOCX_MIME }), 'document.docx');
  form.append('pdfa', GOTENBERG_PDFA);

  const headers = { Authorization: basicAuth(config.gotenberg.username, config.gotenberg.password) };
  if (options.traceId) {
    headers['Gotenberg-Trace'] = options.traceId;
  }
  let response;
  try {
    response = await fetchImpl(`${config.gotenberg.baseUrl}/forms/libreoffice/convert`, {
      method: 'POST',
      headers,
      body: form,
      signal: AbortSignal.timeout(config.gotenberg.timeoutMs),
    });
  } catch {
    throw upstreamFailure('converter_unavailable', 'The PDF converter is not reachable.');
  }
  if (response.status === 503 || response.status === 429) {
    // Gotenberg's LibreOffice queue is full (--libreoffice-max-queue-size),
    // e.g. while a start-first rollout briefly runs two docgen tasks. That is
    // a wait, not a document error: Drupal retries after Retry-After.
    await response.body?.cancel();
    throw overloaded(503, 'docgen_busy', 'The PDF converter queue is full, retry later.', config.retryAfterSeconds ?? 5);
  }
  if (!response.ok) {
    await response.body?.cancel();
    throw upstreamFailure('converter_failed', 'The PDF converter rejected the document.', { status: response.status });
  }
  const pdf = await readCapped(response, config.limits.maxPdfBytes, 'pdf_too_large', 'The converted PDF exceeds the allowed size.');
  if (pdf.subarray(0, 5).toString('latin1') !== '%PDF-') {
    throw upstreamFailure('converter_failed', 'The PDF converter returned no PDF.');
  }
  return pdf;
}

/**
 * Maps a veraPDF-rest JSON report onto the docgen validation contract.
 */
export function summarizeVeraPdfReport(report) {
  const job = report?.report?.jobs?.[0];
  const result = job?.validationResult?.[0];
  if (!result) {
    const exception = job?.taskException;
    const kind = typeof exception?.type === 'string' ? exception.type : 'UNKNOWN';
    return {
      profile: PDFA_PROFILE,
      compliant: false,
      failedRules: 0,
      failedChecks: 0,
      summary: `veraPDF could not validate the file (${kind}).`,
    };
  }
  const details = result.details ?? {};
  const failedRules = Number.isSafeInteger(details.failedRules) ? details.failedRules : 0;
  const failedChecks = Number.isSafeInteger(details.failedChecks) ? details.failedChecks : 0;
  const profileMatches = typeof result.profileName === 'string' && result.profileName.startsWith('PDF/A-2b ');
  const compliant = result.compliant === true
    && result.jobEndStatus === 'normal'
    && profileMatches
    && failedRules === 0
    && failedChecks === 0;

  let summary = typeof result.statement === 'string' ? result.statement : '';
  if (!profileMatches) {
    summary = `Unexpected validation profile. ${summary}`.trim();
  }
  const clauses = (details.ruleSummaries ?? [])
    .filter(rule => rule?.status === 'failed' || rule?.ruleStatus === 'FAILED')
    .slice(0, MAX_REPORTED_CLAUSES)
    .map(rule => `${rule.clause}-${rule.testNumber}`);
  if (clauses.length > 0) {
    summary = `${summary} Failed clauses: ${clauses.join(', ')}${failedRules > clauses.length ? ', ...' : ''}.`;
  }
  return { profile: PDFA_PROFILE, compliant, failedRules, failedChecks, summary };
}

/**
 * Validates a PDF against PDF/A-2b with veraPDF-rest.
 */
export async function validatePdfA(pdf, config, fetchImpl = fetch) {
  const form = new FormData();
  form.append('file', new Blob([pdf], { type: 'application/pdf' }), 'document.pdf');

  let response;
  try {
    response = await fetchImpl(`${config.verapdf.baseUrl}/api/validate/${PDFA_PROFILE}`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: form,
      signal: AbortSignal.timeout(config.verapdf.timeoutMs),
    });
  } catch {
    throw upstreamFailure('validator_unavailable', 'The PDF/A validator is not reachable.');
  }
  if (!response.ok) {
    await response.body?.cancel();
    throw upstreamFailure('validator_failed', 'The PDF/A validator failed.', { status: response.status });
  }
  const raw = await readCapped(response, 16 * 1024 * 1024, 'validator_failed', 'The PDF/A validator report is too large.');
  let report;
  try {
    report = JSON.parse(raw.toString('utf8'));
  } catch {
    throw upstreamFailure('validator_failed', 'The PDF/A validator returned no JSON report.');
  }
  return summarizeVeraPdfReport(report);
}

async function probe(url, init, timeoutMs, fetchImpl) {
  try {
    const response = await fetchImpl(url, { ...init, signal: AbortSignal.timeout(timeoutMs) });
    await response.body?.cancel();
    return response.ok ? 'ok' : 'error';
  } catch {
    return 'unreachable';
  }
}

export async function upstreamHealth(config, fetchImpl = fetch) {
  const [gotenberg, verapdf] = await Promise.all([
    probe(`${config.gotenberg.baseUrl}/health`, { method: 'GET' }, config.healthTimeoutMs, fetchImpl),
    probe(`${config.verapdf.baseUrl}/api/info`, { method: 'GET', headers: { Accept: 'application/json' } }, config.healthTimeoutMs, fetchImpl),
  ]);
  return { gotenberg, verapdf };
}
