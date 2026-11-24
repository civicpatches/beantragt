# docgen

Stateless DOCX template rendering and PDF/A-2b finalization for beantragt.ai
(#108). MIT licensed. One shared service per server for all hosted tenants:
each tenant's Drupal calls it with its own token over its own network;
Gotenberg (LibreOffice, restarted after every document) and veraPDF-rest are
reachable only from docgen.

## API

All routes except `GET /health` need `Authorization: Bearer <token>` of the
tenant; JSON in, JSON out. 429 `tenant_busy` and 503 `docgen_busy` carry
`Retry-After`.

| Route | Request | Response |
|---|---|---|
| `GET /health` | (no token) | `{status, gotenberg, verapdf}` |
| `POST /inspect` | `{template}` (base64 DOCX) | `{placeholders, loops, errors}` |
| `POST /render` | `{template, data}` | `{docx, sha256, missing}` |
| `POST /finalize` | `{docx}` | `{pdf, sha256, docxSha256, validation}` |

## Configuration

| Variable | Default | |
|---|---|---|
| `DOCGEN_TOKENS_FILE` | one of the two | token table of the shared service: `<tenant> <sha256 hex>` per line |
| `DOCGEN_TOKEN` / `DOCGEN_TOKEN_FILE` | one of the two | single tenant (DDEV, on premises), at least 32 characters |
| `DOCGEN_TENANT` | `default` | name of the single tenant (logs, limits) |
| `GOTENBERG_URL` | `http://docgen-gotenberg:3000` | |
| `GOTENBERG_USERNAME` | required | Basic Auth user |
| `GOTENBERG_PASSWORD` / `GOTENBERG_PASSWORD_FILE` | required | at least 16 characters |
| `VERAPDF_URL` | `http://docgen-verapdf:8080` | |
| `DOCGEN_PORT` | `3000` | |
| `DOCGEN_MAX_DOCX_BYTES` | `5242880` | template and draft |
| `DOCGEN_MAX_PDF_BYTES` | `20971520` | converted PDF |
| `DOCGEN_MAX_XML_BYTES` | `4194304` | all XML parts of one DOCX together |
| `GOTENBERG_TIMEOUT_MS`, `VERAPDF_TIMEOUT_MS` | `70000`, `120000` | Gotenberg's own `--api-timeout` is 60 s |
| `DOCGEN_JOB_HEAP_MB`, `DOCGEN_JOB_TIMEOUT_MS` | `256`, `30000` | heap limit and timeout of the worker thread per request |
| `DOCGEN_MAX_CONCURRENT_WORKERS`, `DOCGEN_MAX_QUEUED_WORKERS` | `2`, `8` | worker threads (checks, templating); full queue 503 `docgen_busy` |
| `DOCGEN_MAX_CONCURRENT_JOBS`, `DOCGEN_MAX_QUEUED_JOBS` | `1`, `8` | conversions of `/finalize`; server stack: one per Gotenberg replica; full queue 503 |
| `DOCGEN_TENANT_MAX_CONCURRENT`, `DOCGEN_TENANT_MAX_QUEUED` | `2`, `4` | per tenant over all routes; beyond 429 `tenant_busy` |
| `DOCGEN_RETRY_AFTER_SECONDS` | `5` | `Retry-After` of 429 and 503 |
| `DOCGEN_HEALTH_CACHE_MS` | `5000` | reuse of the upstream probe for `GET /health` |

## Development

```bash
npm ci --omit=dev --ignore-scripts   # four packages, no dev dependencies
npm test                             # unit tests (node:test)
npm run check:deps                   # dependency gate (also runs in the Docker build)
npm ls --all
```

Integration test against the real containers (from the repository root,
after `ddev start` with `docgen` in `COMPOSE_PROFILES` of `.ddev/.env`):

```bash
services/docgen/scripts/test-integration-ddev.sh
```

`npm run test:integration` without `DOCGEN_URL` and a token skips the suite
and says so; a skipped run is not a passed gate.

`scripts/check-dependency-tree.mjs` is run manually, and Docker runs
`scripts/healthcheck.mjs` as the container health check.
`scripts/test-integration-ddev.sh` is run manually, as is
`scripts/measure-throughput.sh` for sizing measurements with 1 and 2 Gotenberg
replicas, using local images only. The shell script starts its client
`scripts/measure-throughput.mjs`. The server stack script
`../../scripts/docgen-stack.sh` is run manually. `runtime/` holds the launch
scripts and the veraPDF server configuration that Compose mounts into the
official images. These files serve as configuration references.

## Offline build

The Dockerfile installs dependencies with a BuildKit cache mount
(`id=beantragt-docgen-npm`) and `npm ci --prefer-offline`. In CI the cache is
empty and npm fetches the four locked packages. Without registry access the
cache mount can be seeded once from a local npm cache that holds the locked
tarballs (a throwaway Dockerfile that copies a `_cacache` directory from a
`--build-context` into the same cache mount id).
