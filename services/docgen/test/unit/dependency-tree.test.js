// The dependency gate itself is covered here: it must pass on the real tree
// and fail on a tree that contains angular-expressions or a paid module.

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { after, describe, it } from 'node:test';

const root = resolve(import.meta.dirname, '../..');
const script = join(root, 'scripts/check-dependency-tree.mjs');
const scratch = mkdtempSync(join(tmpdir(), 'docgen-deps-'));

after(() => rmSync(scratch, { recursive: true, force: true }));

function runCheck(args) {
  return spawnSync(process.execPath, [script, ...args], { encoding: 'utf8' });
}

function fixtureWith(mutate) {
  const directory = mkdtempSync(join(scratch, 'case-'));
  cpSync(join(root, 'package.json'), join(directory, 'package.json'));
  const lock = JSON.parse(readFileSync(join(root, 'package-lock.json'), 'utf8'));
  const manifest = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
  mutate({ lock, manifest });
  writeFileSync(join(directory, 'package-lock.json'), JSON.stringify(lock));
  writeFileSync(join(directory, 'package.json'), JSON.stringify(manifest));
  return directory;
}

describe('check-dependency-tree', () => {
  it('passes on the committed lockfile and installed tree', () => {
    const result = runCheck([]);
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /dependency check: ok/u);
  });

  it('fails when angular-expressions is in the lockfile', () => {
    const directory = fixtureWith(({ lock }) => {
      lock.packages['node_modules/angular-expressions'] = {
        version: '1.7.1',
        resolved: 'https://registry.npmjs.org/angular-expressions/-/angular-expressions-1.7.1.tgz',
        integrity: 'sha512-x',
        license: 'Unlicense',
      };
    });
    const result = runCheck(['--lockfile-only', '--root', directory]);
    assert.equal(result.status, 1);
    assert.match(result.stderr, /forbidden package angular-expressions/u);
  });

  it('fails when a docxtemplater add-on module appears', () => {
    const directory = fixtureWith(({ lock }) => {
      lock.packages['node_modules/docxtemplater-image-module'] = { version: '3.0.0', license: 'SEE LICENSE' };
      lock.packages['node_modules/@docxtemplater/html-module'] = { version: '3.0.0', license: 'SEE LICENSE' };
    });
    const result = runCheck(['--lockfile-only', '--root', directory]);
    assert.equal(result.status, 1);
    assert.match(result.stderr, /forbidden package docxtemplater-image-module/u);
    assert.match(result.stderr, /forbidden package @docxtemplater\/html-module/u);
  });

  it('fails on version ranges and unreviewed packages', () => {
    const directory = fixtureWith(({ lock, manifest }) => {
      manifest.dependencies.pizzip = '^3.3.0';
      lock.packages['node_modules/left-pad'] = {
        version: '1.3.0',
        resolved: 'https://registry.npmjs.org/left-pad/-/left-pad-1.3.0.tgz',
        integrity: 'sha512-x',
        license: 'WTFPL',
      };
    });
    const result = runCheck(['--lockfile-only', '--root', directory]);
    assert.equal(result.status, 1);
    assert.match(result.stderr, /exact version/u);
    assert.match(result.stderr, /unreviewed package left-pad/u);
  });
});
