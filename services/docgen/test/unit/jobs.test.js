import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import PizZip from 'pizzip';
import { createJobRunner } from '../../src/jobs.js';
import { buildDocx } from '../helpers/docx-fixture.js';
import { testConfig } from '../helpers/config.js';

function runnerWith(jobs, limits = {}) {
  const config = testConfig();
  return createJobRunner({ ...config, limits: { ...config.limits, ...limits }, jobs: { ...config.jobs, ...jobs } });
}

describe('job runner (worker threads)', () => {
  it('renders in a worker and returns a Buffer', async () => {
    const runJob = runnerWith({});
    const output = await runJob('render', buildDocx({ paragraphs: ['Hallo {name}'] }), { name: 'Erika' });
    assert.ok(Buffer.isBuffer(output.docx));
    assert.match(new PizZip(output.docx).file('word/document.xml').asText(), /Hallo Erika/u);
    assert.deepEqual(output.missing, []);
    assert.deepEqual(output.sanitized, { removedExternalRelationships: 0 });
  });

  it('keeps the caller buffer intact', async () => {
    const runJob = runnerWith({});
    const docx = buildDocx();
    const copy = Buffer.from(docx);
    await runJob('sanitize', docx);
    assert.deepEqual(docx, copy);
  });

  it('passes input errors through with status and code', async () => {
    const runJob = runnerWith({});
    await assert.rejects(runJob('inspect', Buffer.from('not a zip archive at all, sorry')), (error) => {
      assert.equal(error.status, 422);
      assert.equal(error.code, 'invalid_docx');
      return true;
    });
  });

  it('answers 422 when a document exhausts the worker heap, and keeps serving', async () => {
    // Dense XML costs far more heap than its size; a small heap limit makes
    // the worker run out of memory on a document that passes every byte limit.
    const runJob = runnerWith({ heapMb: 16 }, { maxXmlBytes: 64 * 1024 * 1024, maxCompressionRatio: 1e9 });
    const dense = '<w:p/><w:p/><w:p/><w:p/><w:r><w:instrText>X</w:instrText></w:r>'.repeat(60_000);
    await assert.rejects(runJob('render', buildDocx({ paragraphs: [], bodyXml: dense }), {}), (error) => {
      assert.equal(error.status, 422);
      assert.equal(error.code, 'docx_too_complex');
      return true;
    });
    const output = await runJob('inspect', buildDocx({ paragraphs: ['{a}'] }));
    assert.deepEqual(output.result.placeholders, ['a']);
  });

  it('ends a job that runs past the timeout', async () => {
    const runJob = runnerWith({ timeoutMs: 1 });
    await assert.rejects(runJob('inspect', buildDocx()), error => error.code === 'docx_too_complex');
  });

  it('answers 503 when the queue is full', async () => {
    const runJob = runnerWith({ maxConcurrent: 1, maxQueued: 1 });
    const docx = buildDocx();
    const results = await Promise.allSettled([runJob('inspect', docx), runJob('inspect', docx), runJob('inspect', docx)]);
    assert.equal(results.filter(item => item.status === 'fulfilled').length, 2);
    const rejected = results.find(item => item.status === 'rejected');
    assert.equal(rejected.reason.status, 503);
    assert.equal(rejected.reason.code, 'docgen_busy');
  });
});
