// Integration test against the real containers (docgen, Gotenberg, veraPDF).
//
// Needs DOCGEN_URL and DOCGEN_TOKEN or DOCGEN_TOKEN_FILE. Run it from a
// container in the network that reaches docgen, for example with
// scripts/test-integration-ddev.sh. Skips with a clear message otherwise, so
// `npm run test:integration` without a stack is reported as not run.

import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { before, describe, it } from 'node:test';
import PizZip from 'pizzip';
import { buildDocx, NAMESPACES } from '../helpers/docx-fixture.js';

const baseUrl = process.env.DOCGEN_URL;
const token = process.env.DOCGEN_TOKEN_FILE
  ? readFileSync(process.env.DOCGEN_TOKEN_FILE, 'utf8').trim()
  : process.env.DOCGEN_TOKEN;
const skip = !baseUrl || !token ? 'DOCGEN_URL and DOCGEN_TOKEN(_FILE) are not set: integration test NOT RUN' : false;

// veraPDF runs emulated on Apple Silicon; a cold check can take a minute.
const SLOW = { timeout: 300_000 };

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

async function call(method, path, body) {
  const response = await fetch(`${baseUrl}${path}`, {
    method,
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
    signal: AbortSignal.timeout(280_000),
  });
  return { status: response.status, body: await response.json() };
}

const TEMPLATE_PARAGRAPHS = [
  'Stadt Musterhausen, Bürgeramt',
  'Bescheid zum Antrag {aktenzeichen}',
  'Sehr geehrte Frau {nachname},',
  'Ihr Antrag vom {antragsdatum} wird wie folgt beschieden:',
  '{#positionen}',
  '{bezeichnung}: {betrag}',
  '{/positionen}',
  'Rechtsbehelfsbelehrung: Gegen diesen Bescheid kann innerhalb eines Monats Widerspruch erhoben werden.',
  'Mit freundlichen Grüßen, {sachbearbeitung}',
];

describe('docgen against the real containers', { skip }, () => {
  before(async () => {
    // Wait for the health of docgen and both upstreams (veraPDF starts slowly).
    const deadline = Date.now() + 180_000;
    let last;
    while (Date.now() < deadline) {
      try {
        last = await call('GET', '/health');
        if (last.status === 200) {
          return;
        }
      } catch (error) {
        last = { error: error.message };
      }
      await new Promise(resolve => setTimeout(resolve, 3000));
    }
    throw new Error(`docgen did not become healthy: ${JSON.stringify(last)}`);
  });

  it('reports healthy upstreams', async () => {
    const { status, body } = await call('GET', '/health');
    assert.equal(status, 200);
    assert.deepEqual(body, { status: 'ok', gotenberg: 'ok', verapdf: 'ok' });
  });

  it('answers health without token, but nothing else', async () => {
    const health = await fetch(`${baseUrl}/health`);
    assert.equal(health.status, 200);
    assert.deepEqual(Object.keys(await health.json()).sort(), ['gotenberg', 'status', 'verapdf']);
    const response = await fetch(`${baseUrl}/inspect`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ template: buildDocx().toString('base64') }),
    });
    assert.equal(response.status, 401);
  });

  it('inspects, renders and finalizes into veraPDF-compliant PDF/A-2b', SLOW, async () => {
    const template = buildDocx({ paragraphs: TEMPLATE_PARAGRAPHS });

    const inspected = await call('POST', '/inspect', { template: template.toString('base64') });
    assert.equal(inspected.status, 200);
    assert.deepEqual(inspected.body, {
      placeholders: ['aktenzeichen', 'antragsdatum', 'nachname', 'positionen.betrag', 'positionen.bezeichnung', 'sachbearbeitung'],
      loops: ['positionen'],
      errors: [],
    });

    const rendered = await call('POST', '/render', {
      template: template.toString('base64'),
      data: {
        aktenzeichen: 'BA-2026-0042',
        nachname: 'Müller',
        antragsdatum: '20.10.2026',
        positionen: [{ bezeichnung: 'Verwaltungsgebühr', betrag: '25,00 €' }, { bezeichnung: 'Auslagen' }],
      },
    });
    assert.equal(rendered.status, 200);
    assert.deepEqual(rendered.body.missing, ['positionen.betrag', 'sachbearbeitung']);
    const docx = Buffer.from(rendered.body.docx, 'base64');
    assert.equal(rendered.body.sha256, sha256(docx));

    const finalized = await call('POST', '/finalize', { docx: rendered.body.docx });
    assert.equal(finalized.status, 200, JSON.stringify(finalized.body));
    const pdf = Buffer.from(finalized.body.pdf, 'base64');
    assert.equal(pdf.subarray(0, 5).toString('latin1'), '%PDF-');
    assert.equal(finalized.body.sha256, sha256(pdf));
    assert.equal(finalized.body.docxSha256, rendered.body.sha256);
    assert.deepEqual(finalized.body.validation, {
      profile: '2b',
      compliant: true,
      failedRules: 0,
      failedChecks: 0,
      summary: 'PDF file is compliant with Validation Profile requirements.',
    });
    // The XMP metadata declares PDF/A part 2, conformance B.
    const text = pdf.toString('latin1');
    assert.match(text, /pdfaid:part(?:>|=")2/u);
    assert.match(text, /pdfaid:conformance(?:>|=")B/u);
  });

  it('finalizes a draft with external relationships after removing them', SLOW, async () => {
    const draft = buildDocx({
      paragraphs: ['Bitte beachten Sie die Hinweise auf unserer Webseite.'],
      documentRelationships: [
        `<Relationship Id="rIdLink" Type="${NAMESPACES.R}/hyperlink" Target="https://www.example.org/hinweise" TargetMode="External"/>`,
        `<Relationship Id="rIdImg" Type="${NAMESPACES.R}/image" Target="http://169.254.169.254/latest/meta-data" TargetMode="External"/>`,
      ],
      bodyXml: '<w:p><w:hyperlink r:id="rIdLink"><w:r><w:t>Hinweise</w:t></w:r></w:hyperlink></w:p>',
    });
    const finalized = await call('POST', '/finalize', { docx: draft.toString('base64') });
    assert.equal(finalized.status, 200, JSON.stringify(finalized.body));
    assert.equal(finalized.body.docxSha256, sha256(draft));
    assert.deepEqual(finalized.body.sanitized, { removedExternalRelationships: 2 });
    assert.equal(finalized.body.validation.compliant, true, finalized.body.validation.summary);
    assert.doesNotMatch(Buffer.from(finalized.body.pdf, 'base64').toString('latin1'), /169\.254\.169\.254/u);
  });

  it('rejects a macro-enabled draft before conversion', async () => {
    const docm = buildDocx({ mainContentType: 'application/vnd.ms-word.document.macroEnabled.main+xml' });
    const { status, body } = await call('POST', '/finalize', { docx: docm.toString('base64') });
    assert.equal(status, 422);
    assert.equal(body.error, 'docx_macro_or_template');
  });

  it('rejects an outside-loading field in a part stored without .xml extension', async () => {
    const header = `<?xml version="1.0" encoding="UTF-8"?><w:hdr xmlns:w="${NAMESPACES.W}"><w:p>`
      + '<w:fldSimple w:instr=" INCLUDETEXT &quot;/etc/hostname&quot; "><w:r><w:t>x</w:t></w:r></w:fldSimple></w:p></w:hdr>';
    const draft = buildDocx({
      documentRelationships: [`<Relationship Id="rIdH" Type="${NAMESPACES.R}/header" Target="h1.bin"/>`],
      extraContentTypes: ['<Override PartName="/word/h1.bin" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'],
      extraEntries: { 'word/h1.bin': header },
    });
    const { status, body } = await call('POST', '/finalize', { docx: draft.toString('base64') });
    assert.equal(status, 422);
    assert.equal(body.error, 'docx_forbidden_content');
  });

  it('rejects a DOCX whose XML parts exceed the XML budget', async () => {
    const filler = Array.from({ length: 70_000 }, (_, index) => `<w:p><w:r><w:t>Zeile ${index} ${sha256(String(index)).slice(0, 24)}</w:t></w:r></w:p>`).join('');
    const { status, body } = await call('POST', '/inspect', { template: buildDocx({ paragraphs: [], bodyXml: filler }).toString('base64') });
    assert.equal(status, 422);
    assert.equal(body.error, 'invalid_docx');
    assert.ok(body.details.maxXmlBytes > 0);
  });

  it('keeps rendered output a valid DOCX for the clerk', async () => {
    const template = buildDocx({ paragraphs: ['{a}'] });
    const rendered = await call('POST', '/render', { template: template.toString('base64'), data: { a: 'x' } });
    const zip = new PizZip(Buffer.from(rendered.body.docx, 'base64'));
    assert.ok(zip.file('[Content_Types].xml'));
    assert.ok(zip.file('word/document.xml'));
  });
});
