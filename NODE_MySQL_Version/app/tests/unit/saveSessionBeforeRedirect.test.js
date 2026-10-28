'use strict';

const http = require('http');
const express = require('express');
const session = require('express-session');
const flash = require('../../src/helpers/flash');
const saveSessionBeforeRedirect = require('../../src/middleware/saveSessionBeforeRedirect');

/**
 * QA Round 5, R5-15: on Node a confirmation message was intermittently
 * missing after an action. express-session sends a redirect's headers
 * before an asynchronous store has finished saving the session, so the
 * browser could load the next page before the flash message was stored.
 * A slow store makes the race certain here: the redirect must not reach
 * the browser until the session holding the message is saved.
 */
describe('saveSessionBeforeRedirect (QA Round 5, R5-15)', () => {
  class SlowStore extends session.MemoryStore {
    constructor() {
      super();
      this.savedAt = null;
    }

    set(sid, sess, cb) {
      setTimeout(() => {
        super.set(sid, sess, (err) => {
          this.savedAt = Date.now();
          cb(err);
        });
      }, 150);
    }
  }

  function startApp(withFix) {
    const store = new SlowStore();
    const app = express();
    app.use(session({ store, secret: 'test-secret', resave: false, saveUninitialized: false }));
    if (withFix) app.use(saveSessionBeforeRedirect);
    app.post('/act', (req, res) => {
      flash.set(req, 'success', 'Saved.');
      res.redirect('/next');
    });
    return new Promise((resolve) => {
      const server = app.listen(0, '127.0.0.1', () => resolve({ server, store }));
    });
  }

  function postAndTimeHeaders(server) {
    return new Promise((resolve, reject) => {
      const req = http.request({ host: '127.0.0.1', port: server.address().port, method: 'POST', path: '/act' }, (res) => {
        const headersAt = Date.now();
        res.resume();
        res.on('end', () => resolve({ headersAt, status: res.statusCode, location: res.headers.location }));
      });
      req.on('error', reject);
      req.end();
    });
  }

  it('sends the redirect only after the session holding the flash message is saved', async () => {
    const { server, store } = await startApp(true);
    try {
      const { headersAt, status, location } = await postAndTimeHeaders(server);
      expect(status).toBe(302);
      expect(location).toBe('/next');
      expect(store.savedAt).not.toBeNull();
      expect(headersAt).toBeGreaterThanOrEqual(store.savedAt);
    } finally {
      server.close();
    }
  });

  it('without it, express-session sends the redirect before the save finishes (the race this closes)', async () => {
    const { server, store } = await startApp(false);
    try {
      const { headersAt } = await postAndTimeHeaders(server);
      expect(store.savedAt).not.toBeNull();
      expect(headersAt).toBeLessThan(store.savedAt);
    } finally {
      server.close();
    }
  });

  it('a request without a session redirects straight away', () => {
    const req = {};
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    saveSessionBeforeRedirect(req, res, () => {});
    res.redirect('/login');
    expect(res.redirectedTo).toBe('/login');
  });
});
