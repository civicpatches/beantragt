import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import PizZip from 'pizzip';
import { DEFAULT_LIMITS } from '../../src/config.js';
import { guardDocx } from '../../src/docx-guard.js';
import { inspectTemplate, renderTemplate } from '../../src/templating.js';
import { buildDocx } from '../helpers/docx-fixture.js';

const limits = DEFAULT_LIMITS;

function template(paragraphs) {
  return guardDocx(buildDocx({ paragraphs }), limits).zip;
}

function bodyText(docx) {
  const xml = new PizZip(docx).file('word/document.xml').asText();
  return [...xml.matchAll(/<w:p\b[^>]*>(.*?)<\/w:p>/gsu)]
    .map(match => [...match[1].matchAll(/<w:t\b[^>]*>([^<]*)<\/w:t>|<w:br\/>/gu)].map(t => (t[1] ?? '\n')).join(''))
    .filter(text => text !== '');
}

describe('inspectTemplate', () => {
  it('lists placeholders and loops with dotted paths', () => {
    const result = inspectTemplate(template([
      'Aktenzeichen {aktenzeichen}',
      '{#positionen}',
      '{bezeichnung}: {betrag}',
      '{/positionen}',
      '{#bedingung}Nur wenn{/bedingung}',
    ]));
    assert.deepEqual(result, {
      placeholders: ['aktenzeichen', 'positionen.betrag', 'positionen.bezeichnung'],
      loops: ['bedingung', 'positionen'],
      errors: [],
    });
  });

  it('returns template errors instead of throwing', () => {
    const result = inspectTemplate(template(['{#offen}', '{name}']));
    assert.deepEqual(result.placeholders, []);
    assert.equal(result.errors.length, 1);
    assert.equal(result.errors[0].id, 'unclosed_loop');
    assert.equal(result.errors[0].tag, 'offen');
  });

  it('reports raw XML tags as errors', () => {
    const result = inspectTemplate(template(['{@roh}']));
    assert.deepEqual(result.errors.map(error => error.id), ['raw_xml_forbidden']);
  });
});

describe('renderTemplate', () => {
  it('renders values, escapes XML and reports missing placeholders', () => {
    const { docx, missing } = renderTemplate(template([
      'Sehr geehrte {anrede} {name},',
      '{#positionen}',
      '{bezeichnung}: {betrag}',
      '{/positionen}',
      '{ort}',
    ]), {
      name: 'Müller <b>&amp;',
      positionen: [{ bezeichnung: 'Gebühr', betrag: '12,00 €' }, { bezeichnung: 'Auslagen' }],
    }, limits.maxRenderedDocxBytes);

    assert.deepEqual(missing, ['anrede', 'ort', 'positionen.betrag']);
    assert.deepEqual(bodyText(docx), [
      'Sehr geehrte  Müller &lt;b&gt;&amp;amp;,',
      'Gebühr: 12,00 €',
      'Auslagen: ',
    ]);
  });

  it('reports a missing loop and renders it empty', () => {
    const { docx, missing } = renderTemplate(template(['A', '{#liste}', 'x {y}', '{/liste}', 'B']), {}, limits.maxRenderedDocxBytes);
    assert.deepEqual(missing, ['liste']);
    assert.deepEqual(bodyText(docx), ['A', 'B']);
  });

  it('turns line breaks into Word line breaks', () => {
    const { docx } = renderTemplate(template(['{adresse}']), { adresse: 'Rathausplatz 1\n12345 Musterstadt' }, limits.maxRenderedDocxBytes);
    assert.deepEqual(bodyText(docx), ['Rathausplatz 1\n12345 Musterstadt']);
  });

  it('has no expression language and no prototype access', () => {
    const { docx, missing } = renderTemplate(template([
      '{constructor}|{__proto__}|{a.b}|{1+1}|{toString}',
    ]), { a: { b: 'nested' } }, limits.maxRenderedDocxBytes);
    assert.deepEqual(missing, ['1+1', '__proto__', 'a.b', 'constructor', 'toString']);
    assert.deepEqual(bodyText(docx), ['||||']);
  });

  it('rejects raw XML tags', () => {
    assert.throws(() => renderTemplate(template(['{@roh}']), { roh: '<w:p/>' }, limits.maxRenderedDocxBytes), (error) => {
      assert.equal(error.code, 'template_invalid');
      assert.equal(error.details.errors[0].id, 'raw_xml_forbidden');
      return true;
    });
  });

  it('rejects broken templates with 422', () => {
    assert.throws(() => renderTemplate(template(['{offen']), {}, limits.maxRenderedDocxBytes), (error) => {
      assert.equal(error.status, 422);
      assert.equal(error.code, 'template_invalid');
      assert.ok(error.details.errors.length > 0);
      return true;
    });
  });

  it('enforces the output size limit', () => {
    assert.throws(() => renderTemplate(template(['{#rows}{text}{/rows}']), {
      rows: Array.from({ length: 2000 }, (_, index) => ({ text: `${index} ${'x'.repeat(200)} ${Math.random()}` })),
    }, 10 * 1024), (error) => {
      assert.equal(error.status, 413);
      assert.equal(error.code, 'rendered_docx_too_large');
      return true;
    });
  });
});
