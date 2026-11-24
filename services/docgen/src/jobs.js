// Runs DOCX checks and templating in short-lived worker threads.
//
// Each job gets a fresh worker with a V8 heap limit and a wall-clock timeout,
// so a hostile or merely huge document ends its own worker instead of the
// service (a heap OOM in the main thread is fatal and not catchable). Jobs run
// with bounded concurrency (DOCGEN_MAX_CONCURRENT_WORKERS); a full queue
// answers 503 with Retry-After so Drupal can retry. These jobs need no
// LibreOffice; the conversion limit of /finalize is separate (app.js).

import { Worker } from 'node:worker_threads';
import { DocgenError, overloaded, unprocessable } from './errors.js';
import { createLimiter } from './limiter.js';

const WORKER_URL = new URL('./job-worker.js', import.meta.url);

function tooComplex() {
  return unprocessable('docx_too_complex', 'The document is too complex to process within the memory or time limit.');
}

/**
 * @param {object} config Result of loadConfig().
 * @param {object} [options]
 * @param {URL} [options.workerUrl] Test seam.
 */
export function createJobRunner(config, options = {}) {
  const { limits, jobs } = config;
  const workerUrl = options.workerUrl ?? WORKER_URL;
  const limiter = createLimiter({
    maxConcurrent: jobs.maxConcurrent,
    maxQueued: jobs.maxQueued,
    onFull: () => overloaded(503, 'docgen_busy', 'docgen is busy, retry later.', config.retryAfterSeconds ?? 5),
  });

  function execute(operation, docx, data) {
    return new Promise((resolve, reject) => {
      // Copy into a buffer the worker can own; the caller keeps its bytes.
      const input = new Uint8Array(docx);
      const worker = new Worker(workerUrl, {
        workerData: { operation, docx: input, data, limits },
        transferList: [input.buffer],
        resourceLimits: {
          maxOldGenerationSizeMb: jobs.heapMb,
          maxYoungGenerationSizeMb: 16,
          stackSizeMb: 4,
        },
        stdout: true,
        stderr: true,
      });
      // The job settles only after the worker has exited, so its heap is
      // released before the next job starts.
      let outcome = null;
      const settle = (value) => {
        if (outcome === null) {
          outcome = value;
        }
      };
      const timer = setTimeout(() => {
        settle({ error: tooComplex() });
        worker.terminate().catch(() => {});
      }, jobs.timeoutMs);
      worker.once('message', (message) => {
        if (message.ok) {
          const output = message.output;
          if (output.docx) {
            output.docx = Buffer.from(output.docx.buffer, output.docx.byteOffset, output.docx.byteLength);
          }
          settle({ output });
        } else if (message.error) {
          const { status, code, message: text, details } = message.error;
          settle({ error: new DocgenError(status, code, text, details) });
        } else {
          settle({ error: new Error(`docgen job failed (${message.name})`) });
        }
        worker.terminate().catch(() => {});
      });
      worker.once('error', (error) => {
        settle({ error: error?.code === 'ERR_WORKER_OUT_OF_MEMORY' ? tooComplex() : new Error('docgen job failed') });
      });
      worker.once('exit', () => {
        clearTimeout(timer);
        settle({ error: tooComplex() });
        if (outcome.error) {
          reject(outcome.error);
        } else {
          resolve(outcome.output);
        }
      });
    });
  }

  /**
   * @param {'inspect'|'render'|'sanitize'} operation
   * @param {Buffer} docx Received DOCX bytes.
   * @param {object} [data] Render data (render only).
   */
  function runJob(operation, docx, data = undefined) {
    return limiter.run(() => execute(operation, docx, data));
  }
  runJob.hasCapacity = () => limiter.hasCapacity();
  return runJob;
}
