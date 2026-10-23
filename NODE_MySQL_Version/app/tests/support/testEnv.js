'use strict';

// Runs once per test file, before the test framework installs and before
// any test file requires app code — so src/config/env.js's dotenv.config()
// (which never overrides an already-set process.env value) picks these up
// instead of the real .env's dev/demo database. Every test run points at
// its own disposable database, never at nexacrest_node_test (the
// interactively-used dev/demo DB with real sample data).
// 'local' (not a separate 'test' value — the app only branches on 'local')
// so cookies aren't marked Secure (supertest talks plain HTTP) and 2FA
// codes are returned directly instead of requiring a real SMS/email send.
process.env.APP_ENV = 'local';
process.env.DB_DATABASE = 'nexacrest_node_jest';
process.env.PORT = '0'; // let the OS pick an ephemeral port if server.js's app.listen() runs at require-time
process.env.RUN_JOBS_IN_PROCESS = 'false';
process.env.SESSION_SECRET_KEY = process.env.SESSION_SECRET_KEY || 'jest-test-session-secret';

// External QA report TI-05: the suite was never isolated from a
// developer's own .env — with real SMTP credentials configured there,
// emailService's transport() would actually attempt to send mail during
// a test run (and several tests failed outright because they assert the
// "no SMTP configured" dev-fallback path). dotenv.config() in
// src/config/env.js never overrides an already-set process.env value, so
// setting this here, before any app code (and therefore env.js) is
// required, is what makes it win and every test run deterministic
// regardless of the machine it runs on.
process.env.SMTP_HOST = '';
