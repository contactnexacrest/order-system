'use strict';

const { parseDbDateTime } = require('../../src/helpers/dbDateTime');

/**
 * QA-5 DEF-04 follow-on: pins parseDbDateTime's contract — a naive
 * "Y-m-d H:i:s" value out of MySQL (or a `.toISOString()`-based write) is
 * UTC, not local time, regardless of process.env.TZ.
 */
describe('parseDbDateTime (QA-5 DEF-04 follow-on)', () => {
  it('parses a naive DB datetime string as UTC, not local time', () => {
    expect(process.env.TZ).toBe('Asia/Kolkata');

    const parsed = parseDbDateTime('2026-06-15 00:00:00');

    expect(parsed.getTime()).toBe(Date.UTC(2026, 5, 15, 0, 0, 0));
  });

  it('round-trips a toISOString()-based write back to the same instant', () => {
    const original = new Date('2026-06-15T09:30:00Z');
    const written = original.toISOString().slice(0, 19).replace('T', ' ');

    expect(parseDbDateTime(written).getTime()).toBe(original.getTime());
  });

  it('passes an existing Date instance through unchanged', () => {
    const d = new Date();
    expect(parseDbDateTime(d)).toBe(d);
  });

  it('returns null for null/undefined', () => {
    expect(parseDbDateTime(null)).toBeNull();
    expect(parseDbDateTime(undefined)).toBeNull();
  });
});
