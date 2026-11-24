// Bearer token check against the tenant token table, in constant time.
//
// docgen runs as one shared service per server. Every tenant (municipality,
// customer stack) has its own token; docgen keeps only the SHA-256 of each
// token (token table, see config.js). The presented token is hashed once and
// compared with timingSafeEqual against every entry, without stopping at the
// first match, so neither the token length nor the position of the matching
// tenant in the table shows in the response time.
//
// The tenant identifier is used only for logs and per-tenant limits. It never
// grants access to anything of another tenant, because docgen stores nothing.

import { createHash, timingSafeEqual } from 'node:crypto';

export function sha256Digest(value) {
  return createHash('sha256').update(value, 'utf8').digest();
}

/**
 * @param {{id: string, digest: Buffer}[]} tenants Token table (32-byte digests).
 * @returns {(authorizationHeader: unknown) => string|null} The tenant, or null.
 */
export function createTenantVerifier(tenants) {
  const entries = tenants.map(({ id, digest }) => ({ id, digest: Buffer.from(digest) }));
  return function identify(authorizationHeader) {
    if (typeof authorizationHeader !== 'string') {
      return null;
    }
    const match = /^Bearer ([\x21-\x7e]+)$/u.exec(authorizationHeader);
    if (!match) {
      return null;
    }
    const presented = sha256Digest(match[1]);
    let tenant = null;
    for (const entry of entries) {
      // Evaluate every comparison; config.js rejects duplicate digests.
      const equal = timingSafeEqual(presented, entry.digest);
      if (equal && tenant === null) {
        tenant = entry.id;
      }
    }
    return tenant;
  };
}
