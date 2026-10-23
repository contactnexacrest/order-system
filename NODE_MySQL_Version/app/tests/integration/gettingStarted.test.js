'use strict';

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
    res.renderView = (view, data, layout) => {
      res.renderedView = view;
      res.renderedData = data;
      res.renderedLayout = layout;
      return res;
    };
    return res;
  }

  it('renders with no layout wrapper, for any logged-in user regardless of role', async () => {
    const req = { user: { name: 'Priya Example' }, permissions: {} };
    const res = fakeRes();

    await gettingStartedController.show(req, res);

    expect(res.renderedView).toBe('getting-started/show');
    expect(res.renderedLayout).toBeNull();
  });

  it('passes the signed-in user\'s name through to the view', async () => {
    const req = { user: { name: 'Priya Example' }, permissions: {} };
    const res = fakeRes();

    await gettingStartedController.show(req, res);

    expect(res.renderedData.userName).toBe('Priya Example');
  });

  it('falls back to a null userName when req.user is missing', async () => {
    const req = { permissions: {} };
    const res = fakeRes();

    await gettingStartedController.show(req, res);

    expect(res.renderedData.userName).toBeNull();
  });
});
