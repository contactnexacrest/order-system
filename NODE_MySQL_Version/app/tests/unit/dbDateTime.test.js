'use strict';

const { parseDbDateTime, todayIst, formatForDb } = require('../../src/helpers/dbDateTime');

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

/**
 * 90-item backlog "timezone": `new Date().toISOString().slice(0, 10)` —
 * used all over this codebase before todayIst() existed — is always the
 * UTC calendar date regardless of process.env.TZ. Between 00:00 and 05:29
 * IST (18:30-23:59 UTC the previous day) that returns YESTERDAY's date, a
 * full calendar day behind PHP's date('Y-m-d') at the same instant
 * (date_default_timezone_set('Asia/Kolkata')). todayIst() fixes this by
 * using the LOCAL-time getters, which do follow process.env.TZ.
 */
describe('todayIst() (90-item backlog "timezone")', () => {
  afterEach(() => {
    jest.useRealTimers();
  });

  it('returns the IST date even when the UTC date is still yesterday', () => {
    // 2026-10-16 01:00:00 IST == 2026-10-15 19:30:00 UTC.
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-15T19:30:00Z'));

    expect(todayIst()).toBe('2026-10-16');
  });

  it('matches the UTC date outside the early-morning IST window', () => {
    // 2026-10-16 14:00:00 IST == 2026-10-16 08:30:00 UTC — same calendar day either way.
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-16T08:30:00Z'));

    expect(todayIst()).toBe('2026-10-16');
  });
});

/**
 * External QA report L-21/L-02: every app-computed TIMESTAMP/DATETIME
 * literal (a token expiry, a lockout timer) must be written as IST
 * wall-clock, not UTC — config/db.js now pins the DB session itself to
 * +05:30, so a UTC-formatted literal (toISOString()) would be
 * misread by MySQL as already-IST and land 5h30m off. This was the
 * concretely reproduced bug: a Buyer Action Link's expiry stored as
 * 06:20 instead of 11:50.
 */
describe('formatForDb() (External QA report L-21/L-02)', () => {
  it('formats a UTC instant as its IST wall-clock, not the UTC wall-clock', () => {
    // 2026-10-22 11:50:00 IST == 2026-10-22 06:20:00 UTC.
    const instant = new Date('2026-10-22T06:20:00Z');

    expect(formatForDb(instant)).toBe('2026-10-22 11:50:00');
  });

  it('rolls over to the next IST calendar day near UTC midnight', () => {
    // 2026-10-23 00:15:00 IST == 2026-10-22 18:45:00 UTC.
    const instant = new Date('2026-10-22T18:45:00Z');

    expect(formatForDb(instant)).toBe('2026-10-23 00:15:00');
  });

  it('pads single-digit month/day/hour/minute/second components', () => {
    // 2026-01-02 03:04:05 IST == 2026-01-01 21:34:05 UTC.
    const instant = new Date('2026-01-01T21:34:05Z');

    expect(formatForDb(instant)).toBe('2026-01-02 03:04:05');
  });
});
