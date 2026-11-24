import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createServer, request as httpRequest } from 'node:http';
import { after, before, beforeEach, describe, it } from 'node:test';
import PizZip from 'pizzip';
import { createApp, guardRequestHandler, isCanonicalBase64 } from '../../src/app.js';
import { buildDocx } from '../helpers/docx-fixture.js';
import { TEST_TOKEN, testConfig } from '../helpers/config.js';

const FAKE_PDF = Buffer.from('%PDF-1.7\n% fake pdf for tests\n%%EOF\n');
const COMPLIANT_REPORT = {
  report: {
    jobs: [{
      validationResult: [{
        compliant: true,
        jobEndStatus: 'normal',
        profileName: 'PDF/A-2b validation profile',
        statement: 'PDF file is compliant with Validation Profile requirements.',
        details: { passedRules: 144, failedRules: 0, passedChecks: 784, failedChecks: 0, ruleSummaries: [] },
      }],
    }],
  },
};

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

/** Records upstream calls and answers like Gotenberg and veraPDF-rest. */
function createUpstream() {
  const upstream = {
    calls: [],
    gotenbergResponse: () => new Response(FAKE_PDF, { status: 200, headers: { 'Content-Type': 'application/pdf' } }),
    verapdfResponse: () => Response.json(COMPLIANT_REPORT),
    healthy: true,
  };
  upstream.fetch = async (url, init = {}) => {
    const target = new URL(url);
    const call = { url: target.toString(), method: init.method, headers: init.headers ?? {}, form: null };
    if (init.body instanceof FormData) {
      call.form = {};
      for (const [key, value] of init.body.entries()) {
        call.form[key] = typeof value === 'string' ? value : { name: value.name, type: value.type, bytes: Buffer.from(await value.arrayBuffer()) };
      }
    }
    upstream.calls.push(call);
    if (target.pathname === '/health' || target.pathname === '/api/info') {
      if (!upstream.healthy) {
        throw new TypeError('fetch failed');
      }
      return Response.json({ status: 'up' });
    }
    if (target.host === 'gotenberg.test:3000' && target.pathname === '/forms/libreoffice/convert') {
      return upstream.gotenbergResponse();
    }
    if (target.host === 'verapdf.test:8080' && target.pathname === '/api/validate/2b') {
      return upstream.verapdfResponse();
    }
    return new Response('unexpected upstream call', { status: 599 });
  };
  return upstream;
}

describe('docgen HTTP API', () => {
  let server;
  let baseUrl;
  let upstream;
  const logs = [];

  before(async () => {
    upstream = createUpstream();
    server = createServer(createApp(testConfig({ DOCGEN_HEALTH_CACHE_MS: '0' }), { fetch: (...args) => upstream.fetch(...args), log: line => logs.push(line) }));
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    baseUrl = `http://127.0.0.1:${server.address().port}`;
  });

  after(() => new Promise(resolve => server.close(resolve)));

  beforeEach(() => {
    // Reset state but keep the fetch closure bound to this object.
    const { fetch: _unused, ...fresh } = createUpstream();
    Object.assign(upstream, fresh);
    logs.length = 0;
  });

  function call(method, path, body, { token = TEST_TOKEN, contentType = 'application/json', raw } = {}) {
    const headers = {};
    if (token !== null) {
      headers.Authorization = `Bearer ${token}`;
    }
    if (body !== undefined || raw !== undefined) {
      headers['Content-Type'] = contentType;
    }
    return fetch(`${baseUrl}${path}`, { method, headers, body: raw ?? (body === undefined ? undefined : JSON.stringify(body)) });
  }

  describe('authentication', () => {
    it('rejects requests without, with a wrong or with a malformed token', async () => {
      const template = buildDocx().toString('base64');
      for (const token of [null, 'wrong-token', `${TEST_TOKEN}x`, '']) {
        const response = await call('POST', '/inspect', { template }, { token });
        assert.equal(response.status, 401, `token ${JSON.stringify(token)}`);
        assert.equal((await response.json()).error, 'unauthorized');
      }
      const basic = await fetch(`${baseUrl}/inspect`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Basic ${Buffer.from(`x:${TEST_TOKEN}`).toString('base64')}` },
        body: JSON.stringify({ template }),
      });
      assert.equal(basic.status, 401);
    });

    it('checks the token before routing', async () => {
      assert.equal((await call('GET', '/does-not-exist', undefined, { token: null })).status, 401);
      assert.equal((await call('POST', '/health', {}, { token: null })).status, 401);
    });

    it('logs the tenant of an authenticated request', async () => {
      await call('POST', '/inspect', { template: buildDocx().toString('base64') });
      assert.ok(logs.some(line => line.path === '/inspect' && line.tenant === 'default' && line.status === 200));
    });

    it('answers 400 to an invalid request target and keeps serving', async () => {
      for (const target of ['//[', '//[::1']) {
        const answer = await new Promise((resolve, reject) => {
          const outgoing = httpRequest(`${baseUrl}/`, { method: 'POST', path: target, headers: { Authorization: `Bearer ${TEST_TOKEN}` } }, (incoming) => {
            const chunks = [];
            incoming.on('data', chunk => chunks.push(chunk));
            incoming.on('end', () => resolve({ status: incoming.statusCode, body: JSON.parse(Buffer.concat(chunks).toString('utf8')) }));
          });
          outgoing.on('error', reject);
          outgoing.end();
        });
        assert.equal(answer.status, 400, target);
        assert.equal(answer.body.error, 'invalid_request_target');
      }
      assert.equal((await call('GET', '/health', undefined, { token: null })).status, 200);
    });

    it('answers 404 and 405 for unknown routes and methods', async () => {
      assert.equal((await call('GET', '/nope')).status, 404);
      assert.equal((await call('GET', '/render')).status, 405);
      assert.equal((await call('POST', '/health', {})).status, 405);
    });
  });

  describe('GET /health', () => {
    it('needs no token and lists no tenants', async () => {
      const response = await call('GET', '/health', undefined, { token: null });
      assert.equal(response.status, 200);
      assert.deepEqual(Object.keys(await response.json()).sort(), ['gotenberg', 'status', 'verapdf']);
    });

    it('reports both upstreams', async () => {
      const response = await call('GET', '/health');
      assert.equal(response.status, 200);
      assert.deepEqual(await response.json(), { status: 'ok', gotenberg: 'ok', verapdf: 'ok' });
      assert.deepEqual(upstream.calls.map(item => item.url).sort(), [
        'http://gotenberg.test:3000/health',
        'http://verapdf.test:8080/api/info',
      ]);
    });

    it('reports a degraded state with 503', async () => {
      upstream.healthy = false;
      const response = await call('GET', '/health');
      assert.equal(response.status, 503);
      assert.deepEqual(await response.json(), { status: 'degraded', gotenberg: 'unreachable', verapdf: 'unreachable' });
    });
  });

  describe('request validation', () => {
    it('requires JSON', async () => {
      const response = await call('POST', '/inspect', undefined, { raw: 'template=x', contentType: 'application/x-www-form-urlencoded' });
      assert.equal(response.status, 415);
    });

    it('rejects malformed JSON and non-object bodies', async () => {
      assert.equal((await call('POST', '/inspect', undefined, { raw: '{' })).status, 400);
      assert.equal((await call('POST', '/inspect', [1, 2])).status, 400);
    });

    it('rejects invalid base64', async () => {
      const response = await call('POST', '/inspect', { template: 'not base64!' });
      assert.equal(response.status, 400);
      assert.equal((await response.json()).error, 'invalid_base64');
    });

    it('checks base64 without a recursive regular expression, up to the DOCX limit', async () => {
      assert.equal(isCanonicalBase64('QUJD'), true);
      assert.equal(isCanonicalBase64('QUI='), true);
      assert.equal(isCanonicalBase64('QQ=='), true);
      for (const value of ['', 'QUJ', 'Q===', 'QU=D', '=QUJ', 'QUJ\u00e4', 'QU J', 'QUJD\n']) {
        assert.equal(isCanonicalBase64(value), false, JSON.stringify(value));
      }
      // 5,003,306 bytes overflowed the old regex (RangeError, HTTP 500)
      // before the worker ran; the full 5 MiB must reach the DOCX guard.
      for (const size of [5_003_306, 5 * 1024 * 1024]) {
        const payload = Buffer.alloc(size, 0x41).toString('base64');
        assert.equal(isCanonicalBase64(payload), true);
        const response = await call('POST', '/inspect', { template: payload });
        assert.equal(response.status, 422, `size ${size}`);
        assert.equal((await response.json()).error, 'invalid_docx');
      }
    });

    it('rejects an oversized DOCX field before decoding', async () => {
      const response = await call('POST', '/inspect', { template: 'A'.repeat(8 * 1024 * 1024) });
      assert.equal(response.status, 413);
      assert.equal((await response.json()).error, 'docx_too_large');
    });

    it('rejects an oversized request body', async () => {
      const response = await call('POST', '/render', { template: 'AAAA', data: { blob: 'x'.repeat(13 * 1024 * 1024) } });
      assert.equal(response.status, 413);
      assert.equal((await response.json()).error, 'request_too_large');
    });

    it('rejects a non-DOCX upload with 422', async () => {
      const response = await call('POST', '/inspect', { template: Buffer.from('%PDF-1.7 nope').toString('base64') });
      assert.equal(response.status, 422);
      assert.equal((await response.json()).error, 'invalid_docx');
    });

    it('rejects a macro document with 422', async () => {
      const docm = buildDocx({ mainContentType: 'application/vnd.ms-word.document.macroEnabled.main+xml' });
      const response = await call('POST', '/finalize', { docx: docm.toString('base64') });
      assert.equal(response.status, 422);
      assert.equal((await response.json()).error, 'docx_macro_or_template');
      assert.equal(upstream.calls.length, 0, 'a rejected draft must never reach the converter');
    });
  });

  describe('POST /inspect', () => {
    it('returns placeholders, loops and errors', async () => {
      const template = buildDocx({ paragraphs: ['{name}', '{#items}', '{label}', '{/items}'] });
      const response = await call('POST', '/inspect', { template: template.toString('base64') });
      assert.equal(response.status, 200);
      assert.deepEqual(await response.json(), { placeholders: ['items.label', 'name'], loops: ['items'], errors: [] });
    });
  });

  describe('POST /render', () => {
    it('returns the DOCX, its hash and missing placeholders', async () => {
      const template = buildDocx({ paragraphs: ['Hallo {name} {nachname}'] });
      const response = await call('POST', '/render', { template: template.toString('base64'), data: { name: 'Erika' } });
      assert.equal(response.status, 200);
      const body = await response.json();
      assert.deepEqual(Object.keys(body).sort(), ['docx', 'missing', 'sanitized', 'sha256']);
      assert.deepEqual(body.sanitized, { removedExternalRelationships: 0 });
      const docx = Buffer.from(body.docx, 'base64');
      assert.equal(body.sha256, sha256(docx));
      assert.deepEqual(body.missing, ['nachname']);
      assert.match(new PizZip(docx).file('word/document.xml').asText(), /Hallo Erika /u);
    });

    it('requires a data object', async () => {
      const template = buildDocx().toString('base64');
      for (const data of [undefined, null, [], 'x']) {
        const response = await call('POST', '/render', { template, data });
        assert.equal(response.status, 400);
      }
    });

    it('does not log document content or data', async () => {
      const template = buildDocx({ paragraphs: ['Geheim {name}'] });
      await call('POST', '/render', { template: template.toString('base64'), data: { name: 'Sozialdaten' } });
      const text = JSON.stringify(logs);
      assert.doesNotMatch(text, /Sozialdaten|Geheim/u);
      assert.ok(logs.some(line => line.path === '/render' && line.status === 200));
    });
  });

  describe('POST /finalize', () => {
    it('converts through Gotenberg, validates with veraPDF and hashes both documents', async () => {
      const draft = buildDocx({ paragraphs: ['Bescheid'] });
      const response = await call('POST', '/finalize', { docx: draft.toString('base64') });
      assert.equal(response.status, 200);
      const body = await response.json();
      assert.deepEqual(Object.keys(body).sort(), ['docxSha256', 'pdf', 'sanitized', 'sha256', 'validation']);
      assert.deepEqual(body.sanitized, { removedExternalRelationships: 0 });
      assert.equal(Buffer.from(body.pdf, 'base64').toString(), FAKE_PDF.toString());
      assert.equal(body.sha256, sha256(FAKE_PDF));
      assert.equal(body.docxSha256, sha256(draft));
      assert.deepEqual(body.validation, {
        profile: '2b',
        compliant: true,
        failedRules: 0,
        failedChecks: 0,
        summary: 'PDF file is compliant with Validation Profile requirements.',
      });

      const [convert, validate] = upstream.calls;
      assert.equal(convert.url, 'http://gotenberg.test:3000/forms/libreoffice/convert');
      assert.equal(convert.method, 'POST');
      assert.equal(convert.headers.Authorization, `Basic ${Buffer.from('docgen:gotenberg-test-password').toString('base64')}`);
      assert.equal(convert.form.pdfa, 'PDF/A-2b');
      assert.equal(convert.form.files.name, 'document.docx');
      assert.equal(Object.keys(convert.form).length, 2, 'no downloadFrom, webhook or other fields');

      assert.equal(validate.url, 'http://verapdf.test:8080/api/validate/2b');
      assert.equal(validate.headers.Accept, 'application/json');
      assert.equal(validate.form.file.bytes.toString(), FAKE_PDF.toString());
      assert.ok(upstream.calls.every(item => !item.url.includes('/api/validate/url')));
    });

    it('sends the sanitized DOCX to the converter but hashes the received one', async () => {
      const draft = buildDocx({
        documentRelationships: ['<Relationship Id="rIdX" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="http://evil.test/x.png" TargetMode="External"/>'],
      });
      const response = await call('POST', '/finalize', { docx: draft.toString('base64') });
      const body = await response.json();
      assert.equal(body.docxSha256, sha256(draft));
      assert.deepEqual(body.sanitized, { removedExternalRelationships: 1 });
      const sent = upstream.calls[0].form.files.bytes;
      assert.doesNotMatch(new PizZip(sent).file('word/_rels/document.xml.rels').asText(), /evil\.test/u);
    });

    it('returns a non-compliant validation result unchanged in shape', async () => {
      upstream.verapdfResponse = () => Response.json({
        report: { jobs: [{ validationResult: [{
          compliant: false,
          jobEndStatus: 'normal',
          profileName: 'PDF/A-2b validation profile',
          statement: 'PDF file is not compliant with Validation Profile requirements.',
          details: { failedRules: 3, failedChecks: 8, ruleSummaries: [{ status: 'failed', clause: '6.3.2', testNumber: 1 }] },
        }] }] },
      });
      const body = await (await call('POST', '/finalize', { docx: buildDocx().toString('base64') })).json();
      assert.equal(body.validation.compliant, false);
      assert.equal(body.validation.failedRules, 3);
      assert.equal(body.validation.failedChecks, 8);
      assert.match(body.validation.summary, /6\.3\.2-1/u);
    });

    it('maps converter failures to 502', async () => {
      upstream.gotenbergResponse = () => new Response('boom', { status: 500 });
      const response = await call('POST', '/finalize', { docx: buildDocx().toString('base64') });
      assert.equal(response.status, 502);
      assert.equal((await response.json()).error, 'converter_failed');
    });

    it('rejects a converter answer that is not a PDF', async () => {
      upstream.gotenbergResponse = () => new Response('<html>', { status: 200 });
      const response = await call('POST', '/finalize', { docx: buildDocx().toString('base64') });
      assert.equal(response.status, 502);
    });

    it('enforces the PDF size limit', async () => {
      upstream.gotenbergResponse = () => new Response(Buffer.concat([FAKE_PDF, Buffer.alloc(21 * 1024 * 1024)]), { status: 200 });
      const response = await call('POST', '/finalize', { docx: buildDocx().toString('base64') });
      assert.equal(response.status, 413);
      assert.equal((await response.json()).error, 'pdf_too_large');
      assert.equal(upstream.calls.length, 1, 'an oversized PDF must not reach the validator');
    });

    it('maps an unreachable validator to 502', async () => {
      upstream.verapdfResponse = () => {
        throw new TypeError('fetch failed');
      };
      const response = await call('POST', '/finalize', { docx: buildDocx().toString('base64') });
      assert.equal(response.status, 502);
      assert.equal((await response.json()).error, 'validator_unavailable');
    });
  });
});

describe('guardRequestHandler', () => {
  it('turns a failing handler into a 500 answer instead of an unhandled rejection', async () => {
    const lines = [];
    let unhandled = 0;
    const onUnhandled = () => { unhandled++; };
    process.on('unhandledRejection', onUnhandled);
    const server = createServer(guardRequestHandler(async () => {
      throw new TypeError('boom');
    }, line => lines.push(line)));
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
      const response = await fetch(`http://127.0.0.1:${server.address().port}/inspect`);
      assert.equal(response.status, 500);
      assert.equal((await response.json()).error, 'internal_error');
      await new Promise(resolve => setImmediate(resolve));
      assert.equal(unhandled, 0);
      assert.deepEqual(lines, [{ level: 'error', msg: 'request handler failed', error: 'TypeError' }]);
    } finally {
      process.off('unhandledRejection', onUnhandled);
      await new Promise(resolve => server.close(resolve));
    }
  });
});
