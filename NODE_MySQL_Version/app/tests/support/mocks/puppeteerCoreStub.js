'use strict';

// puppeteer-core ships ESM-only and breaks CommonJS `require()` under Jest.
// Tests never need real headless-Chrome PDF rendering (that's covered by
// browser-based manual/E2E verification instead) — this stub lets
// src/services/pdfRenderService.js load under Jest without pulling in the
// real ESM package. Any test that actually calls .launch() will get a
// clear rejection rather than silently producing fake output.
module.exports = {
  launch: async () => {
    throw new Error('puppeteer-core is stubbed out under Jest — this test path should not render a real PDF.');
  },
};
