'use strict';

const db = require('../../src/config/db');
const permissionAdminController = require('../../src/controllers/permissionAdminController');
const complianceTaskTypeController = require('../../src/controllers/complianceTaskTypeController');
const roleRepository = require('../../src/repositories/roleRepository');

/**
 * External QA report R-06/R-15: "Array-shaped input creates junk master
 * records: role named 'Array' (PHP), 'a,b' (Node)." Express's urlencoded
 * parser turns `name[]=a&name[]=b` into a real array, and
 * `String(['a', 'b']).trim()` silently joins it to "a,b", which then
 * sailed past the empty-value check and got stored as real data.
 * scalarString() now rejects array input explicitly, falling back to ''
 * so the caller's existing "name is required" validation catches it.
 */
describe('Array-shaped input rejected for name fields (External QA report R-06/R-15)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('roleCreate rejects array-shaped name instead of storing "a,b"', async () => {
    const req = { body: { name: ['a', 'b'] }, user: { id: 1 }, session: {} };

    await permissionAdminController.roleCreate(req, fakeRes());

    expect(await roleRepository.findByName('a,b')).toBeNull();
    const [flashEntry] = req.session._flash || [];
    expect(flashEntry && flashEntry.type).toBe('error');
    expect(flashEntry && flashEntry.message).toContain('required');
  });

  it('complianceTaskType create rejects array-shaped name instead of storing "a,b"', async () => {
    const req = { body: { name: ['a', 'b'] }, user: { id: 1 }, session: {} };

    await complianceTaskTypeController.create(req, fakeRes());

    const [flashEntry] = req.session._flash || [];
    expect(flashEntry && flashEntry.type).toBe('error');
    expect(flashEntry && flashEntry.message).toContain('required');
  });
});
