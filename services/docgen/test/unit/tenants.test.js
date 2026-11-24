// Shared service: token table with several tenants, per-tenant limits (429),
// the global conversion limit (503) and the unauthenticated, cached health route.

import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, before, describe, it } from 'node:test';
import PizZip from 'pizzip';
import { createApp } from '../../src/app.js';
import { loadConfig } from '../../src/config.js';
import { createLimiter } from '../../src/limiter.js';
import { buildDocx } from '../helpers/docx-fixture.js';

const TOKENS = { demo: 'demo-token-0123456789abcdefghijklmnopqrstuv', dunkelstadt: 'dunkelstadt-token-0123456789abcdefghijklmnopqrstu' };
const FAKE_PDF = Buffer.from('%PDF-1.7\n% fake\n%%EOF\n');
const COMPLIANT = { report: { jobs: [{ validationResult: [{
  compliant: true,
  jobEndStatus: 'normal',
  profileName: 'PDF/A-2b validation profile',
  statement: 'PDF file is compliant with Validation Profile requirements.',
  details: { failedRules: 0, failedChecks: 0, ruleSummaries: [] },
}] }] } };

function hex(token) {
  return createHash('sha256').update(token, 'utf8').digest('hex');
}

function deferred() {
  let resolve;
  const promise = new Promise((done) => {
    resolve = done;
  });
  return { promise, resolve };
}

async function until(condition, label) {
  const deadline = Date.now() + 5_000;
  while (!condition()) {
    if (Date.now() > deadline) {
      throw new Error(`timed out waiting for ${label}`);
    }
    await new Promise(resolve => setTimeout(resolve, 5));
  }
}

/**
 * Fake Gotenberg/veraPDF: a conversion of a document containing HOLD waits
 * until the gate opens, so a test can keep slots busy deterministically.
 */
function createUpstream() {
  const upstream = { gate: deferred(), held: 0, probes: 0, convertCalls: 0, queueFull: false, traces: [] };
  upstream.fetch = async (url, init = {}) => {
    const target = new URL(url);
    if (target.pathname === '/health' || target.pathname === '/api/info') {
      upstream.probes += 1;
      return Response.json({ status: 'up' });
    }
    if (target.pathname === '/forms/libreoffice/convert') {
      upstream.convertCalls += 1;
      upstream.traces.push(new Headers(init.headers).get('gotenberg-trace'));
      if (upstream.queueFull) {
        return new Response('queue full', { status: 503 });
      }
      const file = init.body.get('files');
      const xml = new PizZip(Buffer.from(await file.arrayBuffer())).file('word/document.xml').asText();
      if (xml.includes('HOLD')) {
        upstream.held += 1;
        await upstream.gate.promise;
      }
      return new Response(FAKE_PDF, { status: 200 });
    }
    if (target.pathname === '/api/validate/2b') {
      return Response.json(COMPLIANT);
    }
    return new Response('unexpected', { status: 599 });
  };
  return upstream;
}

async function startService(env) {
  const directory = mkdtempSync(join(tmpdir(), 'docgen-tenants-'));
  const tokensFile = join(directory, 'tokens');
  writeFileSync(tokensFile, `# tenant sha256(token)\ndemo ${hex(TOKENS.demo)}\ndunkelstadt ${hex(TOKENS.dunkelstadt)}\n`);
  const config = loadConfig({
    DOCGEN_TOKENS_FILE: tokensFile,
    GOTENBERG_USERNAME: 'docgen',
    GOTENBERG_PASSWORD: 'gotenberg-test-password',
    GOTENBERG_URL: 'http://gotenberg.test:3000',
    VERAPDF_URL: 'http://verapdf.test:8080',
    ...env,
  });
  rmSync(directory, { recursive: true, force: true });
  const upstream = createUpstream();
  const logs = [];
  const server = createServer(createApp(config, { fetch: (...args) => upstream.fetch(...args), log: line => logs.push(line) }));
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const baseUrl = `http://127.0.0.1:${server.address().port}`;
  const call = (tenant, path, body) => fetch(`${baseUrl}${path}`, {
    method: body === undefined ? 'GET' : 'POST',
    headers: {
      ...(tenant ? { Authorization: `Bearer ${TOKENS[tenant]}` } : {}),
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  return { baseUrl, upstream, logs, call, close: () => new Promise(resolve => server.close(resolve)) };
}

const held = () => ({ docx: buildDocx({ paragraphs: ['HOLD'] }).toString('base64') });
const plain = () => ({ docx: buildDocx({ paragraphs: ['Bescheid'] }).toString('base64') });

describe('token table with two tenants', () => {
  let service;
  before(async () => {
    service = await startService({});
  });
  after(() => service.close());

  it('accepts the token of each tenant and logs the tenant', async () => {
    const template = buildDocx({ paragraphs: ['{a}'] }).toString('base64');
    assert.equal((await service.call('demo', '/inspect', { template })).status, 200);
    assert.equal((await service.call('dunkelstadt', '/inspect', { template })).status, 200);
    const tenants = service.logs.filter(line => line.path === '/inspect').map(line => line.tenant);
    assert.deepEqual(tenants, ['demo', 'dunkelstadt']);
  });

  it('rejects an unknown token', async () => {
    const response = await fetch(`${service.baseUrl}/inspect`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${'x'.repeat(43)}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ template: 'AAAA' }),
    });
    assert.equal(response.status, 401);
    assert.ok(service.logs.some(line => line.status === 401 && line.tenant === undefined));
  });
});

describe('per-tenant limits', () => {
  let service;
  before(async () => {
    service = await startService({
      DOCGEN_TENANT_MAX_CONCURRENT: '1',
      DOCGEN_TENANT_MAX_QUEUED: '1',
      DOCGEN_MAX_CONCURRENT_JOBS: '4',
      DOCGEN_RETRY_AFTER_SECONDS: '7',
    });
  });
  after(() => service.close());

  it('answers 429 with Retry-After to a full tenant while the other tenant is served', async () => {
    // demo: one call runs (held in the converter), one waits, the third is refused.
    const running = service.call('demo', '/finalize', held());
    await until(() => service.upstream.held === 1, 'the first demo conversion');
    const second = service.call('demo', '/finalize', plain());
    const third = service.call('demo', '/finalize', plain());
    const refused = await Promise.race([second, third]);
    assert.equal(refused.status, 429);
    assert.equal(refused.headers.get('retry-after'), '7');
    assert.equal((await refused.json()).error, 'tenant_busy');

    // dunkelstadt is not affected by demo's full queue.
    const other = await service.call('dunkelstadt', '/finalize', plain());
    assert.equal(other.status, 200);
    assert.equal((await other.json()).validation.compliant, true);
    const rendered = await service.call('dunkelstadt', '/render', { template: buildDocx({ paragraphs: ['{a}'] }).toString('base64'), data: { a: 'x' } });
    assert.equal(rendered.status, 200);

    // Releasing demo's slot lets its waiting call finish.
    service.upstream.gate.resolve();
    assert.equal((await running).status, 200);
    const results = await Promise.all([second, third]);
    assert.deepEqual(results.map(response => response.status).sort(), [200, 429]);
    assert.ok(service.logs.some(line => line.tenant === 'demo' && line.status === 429));
  });
});

describe('global conversion limit', () => {
  let service;
  before(async () => {
    service = await startService({
      DOCGEN_MAX_CONCURRENT_JOBS: '1',
      DOCGEN_MAX_QUEUED_JOBS: '0',
      DOCGEN_TENANT_MAX_CONCURRENT: '4',
    });
  });
  after(() => service.close());

  it('answers 503 with Retry-After when every converter slot is busy, but keeps rendering', async () => {
    const running = service.call('demo', '/finalize', held());
    await until(() => service.upstream.held === 1, 'the demo conversion');
    const busy = await service.call('dunkelstadt', '/finalize', plain());
    assert.equal(busy.status, 503);
    assert.equal(busy.headers.get('retry-after'), '5');
    assert.equal((await busy.json()).error, 'docgen_busy');
    assert.equal(service.upstream.convertCalls, 1, 'the refused draft never reached the converter');

    // /render and /inspect need no LibreOffice and run in parallel.
    const rendered = await service.call('dunkelstadt', '/render', { template: buildDocx({ paragraphs: ['{a}'] }).toString('base64'), data: { a: 'x' } });
    assert.equal(rendered.status, 200);

    service.upstream.gate.resolve();
    assert.equal((await running).status, 200);
    assert.equal((await service.call('dunkelstadt', '/finalize', plain())).status, 200);
  });
});

describe('GET /health on the shared service', () => {
  let service;
  before(async () => {
    service = await startService({ DOCGEN_HEALTH_CACHE_MS: '60000' });
  });
  after(() => service.close());

  it('needs no token, names no tenant and reuses the upstream probe', async () => {
    const first = await service.call(null, '/health');
    const second = await service.call(null, '/health');
    assert.equal(first.status, 200);
    const body = await second.json();
    assert.deepEqual(body, { status: 'ok', gotenberg: 'ok', verapdf: 'ok' });
    assert.doesNotMatch(JSON.stringify(body), /demo|dunkelstadt/u);
    assert.equal(service.upstream.probes, 2, 'one probe per upstream for both calls');
  });
});

describe('full Gotenberg queue', () => {
  let service;
  before(async () => {
    service = await startService({ DOCGEN_RETRY_AFTER_SECONDS: '9' });
  });
  after(() => service.close());

  it('answers a Gotenberg 503 with a retryable 503 docgen_busy, not 502', async () => {
    service.upstream.queueFull = true;
    const response = await service.call('demo', '/finalize', plain());
    assert.equal(response.status, 503);
    assert.equal(response.headers.get('retry-after'), '9');
    assert.equal((await response.json()).error, 'docgen_busy');
    assert.equal(service.upstream.convertCalls, 1, 'docgen does not retry on its own');
  });

  it('sends its request id to Gotenberg and logs it with the tenant', async () => {
    service.upstream.queueFull = false;
    const response = await service.call('dunkelstadt', '/finalize', plain());
    assert.equal(response.status, 200);
    const requestId = response.headers.get('x-request-id');
    assert.match(requestId, /^[0-9a-f-]{36}$/u);
    assert.equal(service.upstream.traces.at(-1), requestId);
    const line = service.logs.find(entry => entry.requestId === requestId);
    assert.equal(line.tenant, 'dunkelstadt');
    assert.equal(line.path, '/finalize');
  });
});

describe('load shedding before the body is read', () => {
  it('reports capacity while a slot or a queue place is free', async () => {
    const limiter = createLimiter({ maxConcurrent: 1, maxQueued: 1, onFull: () => new Error('full') });
    const gate = deferred();
    assert.equal(limiter.hasCapacity(), true);
    const first = limiter.run(() => gate.promise);
    assert.equal(limiter.hasCapacity(), true, 'one queue place is still free');
    const second = limiter.run(async () => {});
    assert.equal(limiter.hasCapacity(), false);
    gate.resolve();
    await Promise.all([first, second]);
    assert.equal(limiter.hasCapacity(), true);
  });

  it('refuses /render with 503 without reading the body when every worker place is taken', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'docgen-shed-'));
    const tokensFile = join(directory, 'tokens');
    writeFileSync(tokensFile, `demo ${hex(TOKENS.demo)}\n`);
    const config = loadConfig({
      DOCGEN_TOKENS_FILE: tokensFile,
      GOTENBERG_USERNAME: 'docgen',
      GOTENBERG_PASSWORD: 'gotenberg-test-password',
    });
    rmSync(directory, { recursive: true, force: true });
    let jobs = 0;
    const runJob = async () => {
      jobs += 1;
      throw new Error('must not run');
    };
    runJob.hasCapacity = () => false;
    const server = createServer(createApp(config, { runJob, log: () => {} }));
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
      const response = await fetch(`http://127.0.0.1:${server.address().port}/render`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${TOKENS.demo}`, 'Content-Type': 'application/json' },
        body: '{"template": "not even read"',
      });
      assert.equal(response.status, 503, 'invalid JSON would be 400 if the body had been read');
      assert.equal((await response.json()).error, 'docgen_busy');
      assert.equal(jobs, 0);
    } finally {
      await new Promise(resolve => server.close(resolve));
    }
  });
});
