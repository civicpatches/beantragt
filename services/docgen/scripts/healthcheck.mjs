// Container healthcheck (liveness): calls GET /health (no token needed) and
// exits 0 when docgen answers with its JSON status, also when an upstream is
// down (503 "degraded"). Restarting docgen would not fix Gotenberg or veraPDF
// and would abort /inspect and /render calls that do not need them.
// Readiness for callers stays GET /health (200 only when both upstreams are ok).

const port = process.env.DOCGEN_PORT || '3000';

try {
  const response = await fetch(`http://127.0.0.1:${port}/health`, {
    signal: AbortSignal.timeout(8000),
  });
  const body = await response.json();
  const alive = (response.status === 200 && body.status === 'ok')
    || (response.status === 503 && body.status === 'degraded');
  process.exit(alive ? 0 : 1);
} catch {
  process.exit(1);
}
