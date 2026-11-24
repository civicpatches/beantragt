// Worker thread entry: checks and templates one DOCX, then exits.
//
// Runs with its own V8 heap limit (see jobs.js). Parsing untrusted XML into a
// DOM can cost far more memory than the text size; when the heap limit is
// reached only this worker dies and the service answers 422.

import { parentPort, workerData } from 'node:worker_threads';
import { DocgenError } from './errors.js';
import { guardDocx } from './docx-guard.js';
import { inspectTemplate, renderTemplate } from './templating.js';

function run({ operation, docx, data, limits }) {
  const guarded = guardDocx(Buffer.from(docx.buffer, docx.byteOffset, docx.byteLength), limits);
  const sanitized = { removedExternalRelationships: guarded.removedExternalRelationships };
  switch (operation) {
    case 'inspect':
      return { result: inspectTemplate(guarded.zip), sanitized };
    case 'render': {
      const { docx: rendered, missing } = renderTemplate(guarded.zip, data, limits.maxRenderedDocxBytes);
      return { docx: rendered, missing, sanitized };
    }
    case 'sanitize':
      return { docx: guarded.toBuffer(), sanitized };
    default:
      throw new Error(`Unknown job operation: ${operation}`);
  }
}

try {
  const output = run(workerData);
  const transfer = [];
  if (output.docx) {
    // Copy into a Uint8Array that owns its ArrayBuffer (Node buffers may be
    // slices of a shared pool), then transfer it without another copy.
    output.docx = new Uint8Array(output.docx);
    transfer.push(output.docx.buffer);
  }
  parentPort.postMessage({ ok: true, output }, transfer);
} catch (error) {
  if (error instanceof DocgenError) {
    parentPort.postMessage({ ok: false, error: { status: error.status, code: error.code, message: error.message, details: error.details } });
  } else {
    // No message text: it may quote document content.
    parentPort.postMessage({ ok: false, error: null, name: error?.name ?? 'Error' });
  }
}
