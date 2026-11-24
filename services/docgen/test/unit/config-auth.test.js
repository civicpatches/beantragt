import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import { createHash } from 'node:crypto';
import { createTenantVerifier } from '../../src/auth.js';
import { loadConfig, parseTokenTable } from '../../src/config.js';
import { summarizeVeraPdfReport } from '../../src/finalize.js';

function hex(token) {
  return createHash('sha256').update(token, 'utf8').digest('hex');
}

function withTokenTable(text, run) {
  const directory = mkdtempSync(join(tmpdir(), 'docgen-tokens-'));
  try {
    writeFileSync(join(directory, 'tokens'), text);
    return run(join(directory, 'tokens'));
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
}

const VALID = {
  DOCGEN_TOKEN: 'a'.repeat(32),
  GOTENBERG_USERNAME: 'docgen',
  GOTENBERG_PASSWORD: 'b'.repeat(16),
};

describe('loadConfig', () => {
  it('requires a single token of at least 32 characters', () => {
    assert.throws(() => loadConfig({ ...VALID, DOCGEN_TOKEN: '' }), /DOCGEN_TOKEN/u);
    assert.throws(() => loadConfig({ ...VALID, DOCGEN_TOKEN: 'short' }), /DOCGEN_TOKEN/u);
    const { tenants } = loadConfig(VALID);
    assert.equal(tenants.length, 1);
    assert.equal(tenants[0].id, 'default');
    assert.equal(tenants[0].digest.toString('hex'), hex(VALID.DOCGEN_TOKEN));
  });

  it('names the single tenant with DOCGEN_TENANT', () => {
    assert.equal(loadConfig({ ...VALID, DOCGEN_TENANT: 'stadt-dunkelstadt' }).tenants[0].id, 'stadt-dunkelstadt');
    assert.throws(() => loadConfig({ ...VALID, DOCGEN_TENANT: 'Stadt Dunkelstadt' }), /DOCGEN_TENANT/u);
  });

  it('loads the tenant token table from DOCGEN_TOKENS_FILE', () => {
    const { DOCGEN_TOKEN: _single, ...withoutToken } = VALID;
    const table = `# tenant sha256\n\ndemo ${hex('a'.repeat(64))}\ndunkelstadt ${hex('b'.repeat(64))}\n`;
    const config = withTokenTable(table, file => loadConfig({ ...withoutToken, DOCGEN_TOKENS_FILE: file }));
    assert.deepEqual(config.tenants.map(tenant => tenant.id), ['demo', 'dunkelstadt']);
  });

  it('refuses a token table together with a single token', () => {
    const table = `demo ${hex('a'.repeat(64))}\n`;
    assert.throws(
      () => withTokenTable(table, file => loadConfig({ ...VALID, DOCGEN_TOKENS_FILE: file })),
      /not both/u,
    );
  });

  it('uses the per-tenant, worker and conversion limits', () => {
    const config = loadConfig({
      ...VALID,
      DOCGEN_TENANT_MAX_CONCURRENT: '3',
      DOCGEN_TENANT_MAX_QUEUED: '0',
      DOCGEN_MAX_CONCURRENT_JOBS: '2',
      DOCGEN_MAX_CONCURRENT_WORKERS: '4',
    });
    assert.deepEqual({ ...config.tenantLimits }, { maxConcurrent: 3, maxQueued: 0 });
    assert.equal(config.finalize.maxConcurrent, 2);
    assert.equal(config.jobs.maxConcurrent, 4);
    assert.throws(() => loadConfig({ ...VALID, DOCGEN_MAX_CONCURRENT_JOBS: '0' }), /positive/u);
    assert.throws(() => loadConfig({ ...VALID, DOCGEN_TENANT_MAX_QUEUED: '-1' }), /non-negative/u);
  });

  it('requires Gotenberg credentials', () => {
    assert.throws(() => loadConfig({ ...VALID, GOTENBERG_USERNAME: '' }), /GOTENBERG/u);
    assert.throws(() => loadConfig({ ...VALID, GOTENBERG_PASSWORD: 'short' }), /GOTENBERG/u);
  });

  it('reads secrets from *_FILE variables', () => {
    const directory = mkdtempSync(join(tmpdir(), 'docgen-config-'));
    try {
      writeFileSync(join(directory, 'token'), `${'t'.repeat(40)}\n`);
      writeFileSync(join(directory, 'password'), 'p'.repeat(20));
      const config = loadConfig({
        DOCGEN_TOKEN_FILE: join(directory, 'token'),
        GOTENBERG_USERNAME: 'docgen',
        GOTENBERG_PASSWORD_FILE: join(directory, 'password'),
      });
      assert.equal(config.tenants[0].digest.toString('hex'), hex('t'.repeat(40)));
      assert.equal(config.gotenberg.password, 'p'.repeat(20));
    } finally {
      rmSync(directory, { recursive: true, force: true });
    }
  });

  it('rejects upstream URLs with credentials or non-http schemes', () => {
    assert.throws(() => loadConfig({ ...VALID, GOTENBERG_URL: 'http://user:pw@gotenberg:3000' }), /credentials/u);
    assert.throws(() => loadConfig({ ...VALID, VERAPDF_URL: 'file:///etc/passwd' }), /http/u);
  });

  it('uses the contract limits by default', () => {
    const { limits } = loadConfig(VALID);
    assert.equal(limits.maxDocxBytes, 5 * 1024 * 1024);
    assert.equal(limits.maxUncompressedBytes, 50 * 1024 * 1024);
    assert.equal(limits.maxZipEntries, 2000);
    assert.equal(limits.maxRenderedDocxBytes, limits.maxDocxBytes);
  });
});

describe('parseTokenTable', () => {
  it('rejects malformed lines without echoing them', () => {
    const plainToken = 'plain-token-pasted-by-mistake-0123456789';
    assert.throws(() => parseTokenTable(`demo ${plainToken}\n`), (error) => {
      assert.match(error.message, /line 1/u);
      assert.doesNotMatch(error.message, /plain-token/u);
      return true;
    });
    assert.throws(() => parseTokenTable(`Demo ${hex('x')}\n`), /line 1/u);
    assert.throws(() => parseTokenTable(`-demo ${hex('x')}\n`), /line 1/u);
    assert.throws(() => parseTokenTable(`${'a'.repeat(33)} ${hex('x')}\n`), /line 1/u);
    assert.throws(() => parseTokenTable(`demo ${hex('x')} extra\n`), /line 1/u);
  });

  it('rejects duplicate tenants, duplicate tokens and an empty table', () => {
    assert.throws(() => parseTokenTable(`demo ${hex('a')}\ndemo ${hex('b')}\n`), /twice/u);
    assert.throws(() => parseTokenTable(`demo ${hex('a')}\ndunkelstadt ${hex('a')}\n`), /another tenant/u);
    assert.throws(() => parseTokenTable('# only a comment\n'), /no tenant/u);
  });
});

describe('createTenantVerifier', () => {
  const tenants = parseTokenTable(`demo ${hex('d'.repeat(40))}\ndunkelstadt ${hex('k'.repeat(40))}\n`);
  const identify = createTenantVerifier(tenants);

  it('maps each valid token to its tenant', () => {
    assert.equal(identify(`Bearer ${'d'.repeat(40)}`), 'demo');
    assert.equal(identify(`Bearer ${'k'.repeat(40)}`), 'dunkelstadt');
  });

  it('accepts only the exact bearer token', () => {
    assert.equal(identify(`Bearer ${'d'.repeat(39)}`), null);
    assert.equal(identify(`Bearer ${'d'.repeat(40)}x`), null);
    assert.equal(identify(`bearer ${'d'.repeat(40)}`), null);
    assert.equal(identify(`Bearer  ${'d'.repeat(40)}`), null);
    assert.equal(identify(`Bearer ${hex('d'.repeat(40))}`), null, 'the stored hash is not a token');
    assert.equal(identify(undefined), null);
    assert.equal(identify(['Bearer x']), null);
  });
});

describe('summarizeVeraPdfReport', () => {
  it('treats a task exception as not compliant', () => {
    const result = summarizeVeraPdfReport({ report: { jobs: [{ taskException: { type: 'PARSE' } }] } });
    assert.deepEqual(result, {
      profile: '2b',
      compliant: false,
      failedRules: 0,
      failedChecks: 0,
      summary: 'veraPDF could not validate the file (PARSE).',
    });
  });

  it('does not trust a compliant flag from another profile', () => {
    const result = summarizeVeraPdfReport({ report: { jobs: [{ validationResult: [{
      compliant: true,
      jobEndStatus: 'normal',
      profileName: 'PDF/A-1b validation profile',
      statement: 'ok',
      details: { failedRules: 0, failedChecks: 0 },
    }] }] } });
    assert.equal(result.compliant, false);
    assert.match(result.summary, /Unexpected validation profile/u);
  });
});
