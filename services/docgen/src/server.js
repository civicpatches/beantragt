// Process entry point. Fails fast on missing configuration.

import { createServer } from 'node:http';
import { createApp, guardRequestHandler } from './app.js';
import { loadConfig } from './config.js';

let config;
try {
  config = loadConfig();
} catch (error) {
  process.stderr.write(`docgen: configuration error: ${error.message}\n`);
  process.exit(78);
}

const log = line => process.stdout.write(`${JSON.stringify(line)}\n`);
const server = createServer(guardRequestHandler(createApp(config, { log }), log));

// Last line of defence: a rejection nobody handles (a request, a job, an
// upstream call) is logged, never fatal. One tenant's request must not end
// the shared service for all tenants. Synchronous uncaught exceptions still
// end the process; the orchestrator restarts it.
process.on('unhandledRejection', (reason) => {
  log({ level: 'error', msg: 'unhandled rejection', error: reason?.name ?? typeof reason });
});

// Finalize waits for LibreOffice and veraPDF; keep the socket open a little
// longer than both upstream timeouts together.
server.requestTimeout = config.gotenberg.timeoutMs + config.verapdf.timeoutMs + 15_000;
server.headersTimeout = 15_000;
server.keepAliveTimeout = 5_000;
server.maxRequestsPerSocket = 100;

server.listen(config.port, config.host, () => {
  process.stdout.write(`${JSON.stringify({ level: 'info', msg: 'docgen listening', port: config.port })}\n`);
});

function shutdown(signal) {
  process.stdout.write(`${JSON.stringify({ level: 'info', msg: 'shutting down', signal })}\n`);
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 10_000).unref();
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
