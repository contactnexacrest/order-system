'use strict';

const flash = require('../../src/helpers/flash');

/**
 * External QA report R-02: "Raw SQL error text shown to users in the red
 * message box (not only in debug mode — the exception message is passed
 * to the flash). — Where: cancel e-mail send, create amendment, send
 * document." The ~30 `catch (e) { flash.set(req, 'error', e.message); }`
 * call sites across the controllers were correct for a deliberately-
 * thrown business error (`throw new Error('friendly text')`), but the
 * same broad catch also showed a raw mysql2 driver error's message
 * (SQLSTATE text, column names, ...) the exact same way. mysql2 always
 * stamps a driver-originated error with `.sqlState` (see
 * node_modules/mysql2/lib/packets/packet.js's asError()) — something a
 * deliberately-thrown plain Error never has — so that property is the
 * reliable signal flash.describeException()/setErrorFromException() use
 * to tell the two apart.
 */
describe('Flash.describeException/setErrorFromException (External QA report R-02)', () => {
  function fakeReq() {
    return { session: {} };
  }

  it('shows a deliberately-thrown business error\'s real message', () => {
    const req = fakeReq();
    flash.setErrorFromException(req, new Error('This order is already closed.'));

    const [entry] = flash.pull(req);
    expect(entry.type).toBe('error');
    expect(entry.message).toBe('This order is already closed.');
  });

  it('never shows a raw mysql2 driver error\'s message, even though it is a plain Error', () => {
    // Exactly what mysql2's packet.js asError() produces for a real
    // SQLSTATE 22007 (impossible date) — a plain Error with sqlState set.
    const dbError = new Error("Incorrect date value: '2026-02-30' for column 'effective_from' at row 1");
    dbError.code = 'ER_TRUNCATED_WRONG_VALUE';
    dbError.errno = 1292;
    dbError.sqlState = '22007';
    dbError.sqlMessage = dbError.message;

    const req = fakeReq();
    flash.setErrorFromException(req, dbError);

    const [entry] = flash.pull(req);
    expect(entry.message).not.toContain('effective_from');
    expect(entry.message).not.toContain('22007');
    expect(entry.message).toBe('Something went wrong. Please try again.');
  });

  it('uses a custom fallback for an unexpected error when one is provided', () => {
    const dbError = new Error('Duplicate entry');
    dbError.sqlState = '23000';

    const req = fakeReq();
    flash.setErrorFromException(req, dbError, 'Could not approve this request.');

    const [entry] = flash.pull(req);
    expect(entry.message).toBe('Could not approve this request.');
  });

  it('describeException returns the same decision as a plain string', () => {
    expect(flash.describeException(new Error('Deliberate message.'))).toBe('Deliberate message.');

    const dbError = new Error('Column not found');
    dbError.sqlState = '42S22';
    expect(flash.describeException(dbError, 'fallback text')).toBe('fallback text');
  });
});
