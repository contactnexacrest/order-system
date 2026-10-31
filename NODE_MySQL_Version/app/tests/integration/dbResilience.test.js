'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const knex = require('knex');
const request = require('supertest');
const { ConnectSessionKnexStore } = require('connect-session-knex');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { isTransientDbError, ResilientKnexStore } = require('../../src/helpers/dbResilience');

/**
 * QA Round 7, R7-N04: 'Too many connections' from the database made the
 * Node server exit (an unhandled rejection from the session store), so the
 * whole site went down until a manual restart. A transient database error
 * must fail the request it touches (503) and leave the process serving.
 */
describe('A transient database error (R7-N04)', () => {
  const PASSWORD = 'JestResilience!1';
  const tooMany = () => Object.assign(new Error('Too many connections'), { code: 'ER_CON_COUNT_ERROR', errno: 1040 });
  const deadKnex = () => knex({ client: 'mysql2', connection: { host: '127.0.0.1', port: 1, user: 'x', password: 'x', database: 'x' }, pool: { min: 0, max: 1 }, acquireConnectionTimeout: 2000 });

  afterAll(async () => {
    await db.pool.end();
  });

  it('recognises connection errors as transient, and nothing else', () => {
    expect(isTransientDbError(tooMany())).toBe(true);
    expect(isTransientDbError(Object.assign(new Error('refused'), { code: 'ECONNREFUSED' }))).toBe(true);
    expect(isTransientDbError(Object.assign(new Error('x'), { name: 'KnexTimeoutError' }))).toBe(true);
    expect(isTransientDbError(Object.assign(new Error('dup'), { code: 'ER_DUP_ENTRY' }))).toBe(false);
    expect(isTransientDbError(new TypeError('bug'))).toBe(false);
  });

  // express-session calls store.get(sid, callback) and ignores the promise it
  // returns; a rejected one is an unhandled rejection (Jest fails the test on
  // one, as Node used to exit on one).
  it('lets the session store report a database error without an unhandled rejection', async () => {
    const k1 = deadKnex();
    const plain = new ConnectSessionKnexStore({ knex: k1, createTable: false, tableName: 'sessions', sidFieldName: 'sid', cleanupInterval: 0 });
    // The library as it is: the error reaches the callback AND rejects the ignored promise.
    let plainCallback = null;
    await expect(plain.get('sid', (err) => { plainCallback = err; })).rejects.toThrow();
    expect(plainCallback.message).toMatch(/ECONNREFUSED/);
    await k1.destroy();

    const k2 = deadKnex();
    const errors = [];
    const store = new ResilientKnexStore({ knex: k2, createTable: false, tableName: 'sessions', sidFieldName: 'sid', cleanupInterval: 0 }, (e) => errors.push(e));
    const callbackError = await new Promise((resolve) => { store.get('sid', (err) => resolve(err)); });
    await new Promise((r) => setTimeout(r, 100));
    expect(callbackError.message).toMatch(/ECONNREFUSED/);
    expect(errors.length).toBeGreaterThan(0);
    await k2.destroy();
  });

  it('answers 503 for the request that hit it and keeps serving the next one', async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-res-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    expect((await agent.get('/orders')).status).toBe(200);

    const spy = jest.spyOn(db, 'query').mockRejectedValueOnce(tooMany());
    const failed = await agent.get('/orders');
    spy.mockRestore();
    expect(failed.status).toBe(503);
    expect(failed.headers['retry-after']).toBe('10');
    expect(failed.text).toContain('The database is busy or briefly unavailable. Nothing was saved — please wait a moment and try again.');

    expect((await agent.get('/orders')).status).toBe(200);
  });

  it('keeps the process running after an unhandled transient database error, but not after a bug', () => {
    const exit = jest.spyOn(process, 'exit').mockImplementation(() => {});
    const handlers = process.listeners('unhandledRejection');
    try {
      handlers.forEach((h) => h(tooMany(), Promise.resolve()));
      expect(exit).not.toHaveBeenCalled();
      handlers.forEach((h) => h(new TypeError('a real bug'), Promise.resolve()));
      expect(exit).toHaveBeenCalledWith(1);
    } finally {
      exit.mockRestore();
    }
  });
});
