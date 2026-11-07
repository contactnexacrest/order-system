'use strict';

// Owner decision D-265 (7 Nov 2026): every staff and buyer sign-in now needs a 6-digit
// code e-mailed after the password. Hundreds of tests sign in with
// `agent.post('/login')` / `agent.post('/client/login')` only to reach a page they are
// really testing. This test-only helper finishes the code step for them, the way a
// person would: it opens the code page, reads the code the app shows in local dev
// (APP_ENV=local, testEnv.js — production never shows it) and submits it. The awaited
// response is then the code step's redirect — the same place a one-step login used to
// go — so those tests need no change. Nothing in the app is weakened.
//
// A test about the code step itself sets `global.__D265_MANUAL_LOGIN_CODE__ = true`
// (loginCodeD265.test.js, the 2FA case in ungatedRoutes.test.js) to see the raw step.

const TestAgent = require('supertest/lib/agent');

const CODE_PAGES = { '/login': '/2fa', '/client/login': '/client/login/code' };

if (!TestAgent.prototype.__d265Patched) {
  const originalPost = TestAgent.prototype.post;
  TestAgent.prototype.post = function post(url, ...rest) {
    const req = originalPost.call(this, url, ...rest);
    const codePage = CODE_PAGES[url];
    if (!codePage) return req;
    const agent = this;
    const originalThen = req.then;
    req.then = function then(onFulfilled, onRejected) {
      return originalThen.call(req, async (res) => {
        if (global.__D265_MANUAL_LOGIN_CODE__ || res.status !== 302 || res.headers.location !== codePage) return res;
        const page = await agent.get(codePage);
        const code = (page.text.match(/code is: <strong>(\d{6})<\/strong>/) || [])[1];
        const csrf = (page.text.match(/name="_csrf" value="([^"]+)"/) || [])[1];
        if (!code || !csrf) return res;
        return originalPost.call(agent, codePage).type('form').send({ _csrf: csrf, code });
      }).then(onFulfilled, onRejected);
    };
    return req;
  };
  TestAgent.prototype.__d265Patched = true;
}
