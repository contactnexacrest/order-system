'use strict';

/**
 * Which stack the suite runs against — it picks the bcrypt flavour (PHP's $2y$ hashes do not
 * verify in Node's bcrypt package), the default database name and the server log file.
 *
 * QA 13 Nov 2026 (SUI e2e): set E2E_STACK=php or E2E_STACK=node. Without it the old rule is
 * used: BASE_URL containing ":3000" means Node, anything else PHP — which guesses wrong for
 * a Node server on any other port.
 */
function isNodeStack() {
  const stack = String(process.env.E2E_STACK || '').trim().toLowerCase();
  if (stack === 'node') return true;
  if (stack === 'php') return false;
  if (stack !== '') {
    throw new Error(`E2E_STACK must be "php" or "node" (got "${process.env.E2E_STACK}").`);
  }
  return (process.env.BASE_URL || '').includes(':3000');
}

module.exports = { isNodeStack };
