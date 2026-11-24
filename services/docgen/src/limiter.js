// Bounded concurrency with a bounded FIFO queue.
//
// Used three times in docgen: per tenant (src/app.js, 429 when full), for the
// worker threads (src/jobs.js) and for the LibreOffice conversions of
// /finalize (src/app.js), the latter two with 503 when full.

/**
 * @param {object} options
 * @param {number} options.maxConcurrent Calls that may run at the same time (>= 1).
 * @param {number} options.maxQueued Calls that may wait (>= 0); more are rejected.
 * @param {() => Error} options.onFull Builds the error for a rejected call.
 */
export function createLimiter({ maxConcurrent, maxQueued, onFull }) {
  let running = 0;
  const waiting = [];

  function release() {
    const next = waiting.shift();
    if (next) {
      // The slot passes straight to the next caller; `running` stays.
      next();
    } else {
      running -= 1;
    }
  }

  async function acquire() {
    if (running < maxConcurrent) {
      running += 1;
      return;
    }
    if (waiting.length >= maxQueued) {
      throw onFull();
    }
    await new Promise(resolve => waiting.push(resolve));
  }

  return {
    /**
     * Runs `task` once a slot is free; rejects with onFull() when the queue is full.
     * @template T
     * @param {() => Promise<T>} task
     * @returns {Promise<T>}
     */
    async run(task) {
      await acquire();
      try {
        return await task();
      } finally {
        release();
      }
    },
    /**
     * True when an incoming call would run or wait instead of being
     * rejected. app.js asks before it buffers a request body, so a full
     * service sheds load without holding megabytes per refused call.
     */
    hasCapacity() {
      return running < maxConcurrent || waiting.length < maxQueued;
    },
    stats() {
      return { running, queued: waiting.length };
    },
  };
}

/**
 * One limiter per tenant, created for the known tenants up front.
 *
 * @param {string[]} tenants Tenant identifiers from the token table.
 * @param {{maxConcurrent: number, maxQueued: number}} limits Per tenant.
 * @param {(tenant: string) => Error} onFull
 */
export function createTenantLimiter(tenants, limits, onFull) {
  const limiters = new Map(tenants.map(tenant => [tenant, createLimiter({ ...limits, onFull: () => onFull(tenant) })]));
  return {
    run(tenant, task) {
      const limiter = limiters.get(tenant);
      if (!limiter) {
        throw new Error('Unknown tenant.');
      }
      return limiter.run(task);
    },
    stats(tenant) {
      return limiters.get(tenant)?.stats();
    },
  };
}
