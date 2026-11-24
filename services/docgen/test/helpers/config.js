import { loadConfig } from '../../src/config.js';

export const TEST_TOKEN = 'test-token-0123456789abcdefghijklmnopqrstuvwxyz';

export function testConfig(overrides = {}) {
  return loadConfig({
    DOCGEN_TOKEN: TEST_TOKEN,
    GOTENBERG_USERNAME: 'docgen',
    GOTENBERG_PASSWORD: 'gotenberg-test-password',
    GOTENBERG_URL: 'http://gotenberg.test:3000',
    VERAPDF_URL: 'http://verapdf.test:8080',
    ...overrides,
  });
}
