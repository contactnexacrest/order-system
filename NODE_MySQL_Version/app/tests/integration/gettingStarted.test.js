'use strict';

const fs = require('fs');
const path = require('path');
const gettingStartedController = require('../../src/controllers/gettingStartedController');

/**
 * Port of GettingStartedControllerTest.php. The first-day onboarding
 * page — same visibility as sopController's main chapters (logged in is
 * the only requirement, no permission gate), rendered with layout: null
 * since it carries its own standalone navigation rail rather than the
 * usual app shell.
 */
describe('Getting Started controller', () => {
  function fakeRes() {
    const res = {};
    res.send = (body) => { res.sentBody = body; };
    res.renderView = (view, data, layout) => {
      res.renderedView = view;
      res.renderedData = data;
      res.renderedLayout = layout;
      return res;
    };
    return res;
  }

  it('renders with no layout wrapper, for any logged-in user regardless of role', async () => {
    const req = { user: { id: 0, name: 'Example Contact' }, permissions: {} };
    const res = fakeRes();

    await gettingStartedController.show(req, res);

    expect(res.renderedView).toBe('getting-started/show');
    expect(res.renderedLayout).toBeNull();
  });

  // QA Round 7, R7-N16: below 900 px the chapter bar sits above the text and the
  // reference table scrolls in its own box (Playwright 06-responsive checks 390/768 px).
  it('stacks the chapter bar and keeps tables inside the screen on a phone', () => {
    const view = fs.readFileSync(path.join(__dirname, '..', '..', 'views', 'getting-started', 'show.njk'), 'utf8');
    expect(view).toMatch(/@media \(max-width: 880px\)\{\s*\/\*[^*]*R7-N16[^*]*\*\/\s*\.gs-shell\{flex-direction:column;\}/);
    expect(view).toContain('@media (max-width: 880px){ .gs-reftable{display:block; overflow-x:auto;}');
  });

  it('passes the signed-in user\'s name through to the view', async () => {
    const req = { user: { id: 0, name: 'Example Contact' }, permissions: {} };
    const res = fakeRes();

    await gettingStartedController.show(req, res);

    expect(res.renderedData.userName).toBe('Example Contact');
  });

  it('falls back to a null userName when req.user is missing', async () => {
    const req = { permissions: {} };
    const res = fakeRes();

    await gettingStartedController.show(req, res);

    expect(res.renderedData.userName).toBeNull();
  });
});
