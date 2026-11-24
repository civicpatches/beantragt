#!/usr/bin/env node
// Dependency gate for the docgen service (run it, do not import it).
//
// Fails when
// - package.json uses a version range instead of an exact version,
// - the lockfile or the installed tree contains angular-expressions or any
//   docxtemplater add-on module (the paid modules ship as separate packages),
// - a package outside the reviewed allowlist appears,
// - a package license is not one of the reviewed licenses,
// - source code imports the expression parser.
//
// Usage: node scripts/check-dependency-tree.mjs [--lockfile-only] [--root <dir>]

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const rootIndex = process.argv.indexOf('--root');
const root = rootIndex > 0 ? resolve(process.argv[rootIndex + 1]) : resolve(import.meta.dirname, '..');
const errors = [];

const ALLOWED_PACKAGES = new Map([
  ['@xmldom/xmldom', new Set(['MIT'])],
  ['docxtemplater', new Set(['MIT'])],
  ['pizzip', new Set(['(MIT OR GPL-3.0)'])],
  ['pako', new Set(['(MIT AND Zlib)'])],
]);

function isForbidden(name) {
  return name === 'angular-expressions'
    || name === 'angular-parser'
    || name.startsWith('@docxtemplater/')
    || (name.startsWith('docxtemplater') && name !== 'docxtemplater');
}

function packageNameFromLockPath(path) {
  const index = path.lastIndexOf('node_modules/');
  return path.slice(index + 'node_modules/'.length);
}

// 1. Exact versions in package.json.
const manifest = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
for (const section of ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies']) {
  for (const [name, version] of Object.entries(manifest[section] ?? {})) {
    if (!/^\d+\.\d+\.\d+$/u.test(version)) {
      errors.push(`package.json ${section}.${name} must be an exact version, found "${version}"`);
    }
    if (isForbidden(name)) {
      errors.push(`package.json ${section} contains forbidden package ${name}`);
    }
  }
}
if (Object.keys(manifest.devDependencies ?? {}).length > 0) {
  errors.push('package.json must not declare devDependencies (tests use node:test only)');
}

// 2. Lockfile.
const lock = JSON.parse(readFileSync(join(root, 'package-lock.json'), 'utf8'));
if (lock.lockfileVersion !== 3) {
  errors.push(`package-lock.json must be lockfileVersion 3, found ${lock.lockfileVersion}`);
}
const lockedNames = new Set();
for (const [path, entry] of Object.entries(lock.packages ?? {})) {
  if (path === '') {
    continue;
  }
  const name = entry.name ?? packageNameFromLockPath(path);
  lockedNames.add(name);
  if (isForbidden(name)) {
    errors.push(`package-lock.json contains forbidden package ${name} (${path})`);
    continue;
  }
  const allowedLicenses = ALLOWED_PACKAGES.get(name);
  if (!allowedLicenses) {
    errors.push(`package-lock.json contains unreviewed package ${name} (${path}); review it and extend the allowlist`);
    continue;
  }
  if (!allowedLicenses.has(entry.license)) {
    errors.push(`${name} has unreviewed license ${JSON.stringify(entry.license)}`);
  }
  if (typeof entry.resolved !== 'string' || !entry.resolved.startsWith('https://registry.npmjs.org/')) {
    errors.push(`${name} must resolve from https://registry.npmjs.org/, found ${entry.resolved}`);
  }
  if (typeof entry.integrity !== 'string' || !entry.integrity.startsWith('sha512-')) {
    errors.push(`${name} lacks a sha512 integrity hash`);
  }
  if (entry.dev === true) {
    errors.push(`${name} is a dev dependency; the service has none`);
  }
}
for (const name of ALLOWED_PACKAGES.keys()) {
  if (!lockedNames.has(name)) {
    errors.push(`package-lock.json is missing reviewed package ${name}`);
  }
}

// 3. Installed tree (if present), including nested node_modules.
function walkNodeModules(directory) {
  if (!existsSync(directory)) {
    return;
  }
  for (const entry of readdirSync(directory)) {
    if (entry.startsWith('.')) {
      continue;
    }
    const path = join(directory, entry);
    if (!statSync(path).isDirectory()) {
      continue;
    }
    if (entry.startsWith('@')) {
      for (const scoped of readdirSync(path)) {
        inspectInstalled(`${entry}/${scoped}`, join(path, scoped));
      }
      continue;
    }
    inspectInstalled(entry, path);
  }
}
function inspectInstalled(name, path) {
  if (isForbidden(name)) {
    errors.push(`installed tree contains forbidden package ${name} (${path})`);
  } else if (!ALLOWED_PACKAGES.has(name)) {
    errors.push(`installed tree contains unreviewed package ${name} (${path})`);
  }
  walkNodeModules(join(path, 'node_modules'));
}
if (!process.argv.includes('--lockfile-only')) {
  walkNodeModules(join(root, 'node_modules'));
}

// 4. Source must not reach for the expression parser.
function sourceFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      return sourceFiles(path);
    }
    return /\.(m?js|cjs)$/u.test(entry.name) ? [path] : [];
  });
}
for (const file of existsSync(join(root, 'src')) ? sourceFiles(join(root, 'src')) : []) {
  const text = readFileSync(file, 'utf8');
  if (/(?:from\s*|import\s*\(\s*|require\s*\(\s*)['"](?:angular-expressions|angular-parser|docxtemplater\/(?:js\/)?expressions)/u.test(text)) {
    errors.push(`${file} imports the angular expression parser`);
  }
}

if (errors.length > 0) {
  for (const error of errors) {
    process.stderr.write(`dependency check: ${error}\n`);
  }
  process.exit(1);
}
process.stdout.write(`dependency check: ok (${[...lockedNames].sort().join(', ')})\n`);
