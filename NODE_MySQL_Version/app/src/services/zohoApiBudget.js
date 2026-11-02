'use strict';

/**
 * Owner decision 2 Nov 2026 (D-239, "Zoho limits yes"): this app's share of
 * the Zoho API calls of the organisation, which every Zoho app and
 * integration of the company draws on. Port of ZohoApiBudget.php.
 *
 * Every Zoho Books API call (reads and the OAuth token call included) goes
 * through zohoBooksService.request(), which asks acquire() first:
 *  - the day's allowance (setting zoho_api_daily_limit, default 800; the day
 *    runs midnight to midnight India time) used up → ZohoBudgetExhausted,
 *    no call made;
 *  - this minute's allowance (zoho_api_minute_limit, default 30) used up →
 *    the scheduled (background) sync waits for the next minute; anything a
 *    person started waits at most 5 seconds, otherwise ZohoRateLimited with
 *    "Zoho speed limit for this app reached — try again in a minute."
 * Calls are counted in zoho_api_usage (one row per environment and minute /
 * day), with atomic upserts, so web requests, scheduled jobs and both
 * stacks count together. Background mode is per async context
 * (AsyncLocalStorage), so a person's request never inherits the waiting of
 * a scheduled run going on at the same time.
 */

const { AsyncLocalStorage } = require('async_hooks');
const db = require('../config/db');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const zohoGuard = require('./zohoGuard');

const DEFAULT_DAILY = 800;
const DEFAULT_MINUTE = 30;
const SPEED_LIMIT_MESSAGE = 'Zoho speed limit for this app reached — try again in a minute.';
const RATE_LIMIT_MESSAGE = 'Stopped: Zoho rate limit — remaining records will be sent at the next sync.';

/** A reason to stop a Zoho sync run at once; every per-record catch passes it on (rethrow). */
class ZohoSyncStop extends Error {}
/** The daily allowance of Zoho API calls for this app is used up — no call was made. */
class ZohoBudgetExhausted extends ZohoSyncStop {}
/** Zoho answered "too many requests", or this app's per-minute speed limit was reached. */
class ZohoRateLimited extends ZohoSyncStop {}

/** In a per-record catch: let a stop reason end the whole run. */
function rethrow(e) {
  if (e instanceof ZohoSyncStop) throw e;
}

const context = new AsyncLocalStorage();
let clock = null; // tests: () => unix seconds
let sleeper = null; // tests: async (seconds) => void
let testLimits = null; // tests: [daily, minute] instead of the settings

/** Run fn as the scheduled (background) sync: the per-minute limit waits instead of refusing. */
function inBackground(fn) {
  return context.run({ background: true }, fn);
}

function isBackground() {
  const store = context.getStore();
  return !!(store && store.background);
}

/** Tests only: a fake Zoho is not limited unless a test asks for the real limits (useSettingLimits). */
function setTestLimits(daily, minute) {
  testLimits = daily == null || minute == null ? null : [daily, minute];
}
function useSettingLimits() {
  testLimits = null;
}
function setClock(fn) {
  clock = fn;
}
function setSleeper(fn) {
  sleeper = fn;
}

function environment() {
  return zohoGuard.environment();
}

async function dailyLimit() {
  if (testLimits) return testLimits[0];
  const v = parseInt((await companySettingsRepository.get('zoho_api_daily_limit')) ?? '', 10);
  return v > 0 ? v : DEFAULT_DAILY;
}

async function minuteLimit() {
  if (testLimits) return testLimits[1];
  const v = parseInt((await companySettingsRepository.get('zoho_api_minute_limit')) ?? '', 10);
  return v > 0 ? v : DEFAULT_MINUTE;
}

function now() {
  return clock ? Number(clock()) : Math.floor(Date.now() / 1000);
}

/** India-time parts of a unix timestamp. */
function ist(ts) {
  const d = new Date((ts + 330 * 60) * 1000);
  const p = (n) => String(n).padStart(2, '0');
  return {
    day: `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())}`,
    minute: `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`,
    second: d.getUTCSeconds(),
  };
}

function dayKey(ts) {
  return ist(ts ?? now()).day;
}

function minuteKey(ts) {
  return ist(ts ?? now()).minute;
}

async function count(type, bucket) {
  const row = await db.queryOne('SELECT calls FROM zoho_api_usage WHERE environment = :e AND bucket_type = :t AND bucket = :b',
    { e: environment(), t: type, b: bucket });
  return row ? Number(row.calls) : 0;
}

/** Atomic +1 (or -1); returns the new count. */
async function bump(type, bucket, by = 1) {
  await db.execute(`INSERT INTO zoho_api_usage (environment, bucket_type, bucket, calls) VALUES (:e, :t, :b, :n)
                    ON DUPLICATE KEY UPDATE calls = GREATEST(0, calls + :n2)`,
  { e: environment(), t: type, b: bucket, n: Math.max(0, by), n2: by });
  return count(type, bucket);
}

async function exhaustedMessage() {
  return `Paused: daily Zoho allowance for this app (${await dailyLimit()} calls) used — resumes tomorrow.`;
}

async function exhaustedToday() {
  return (await count('day', dayKey())) >= (await dailyLimit());
}

/** Before every Zoho call: reserve one call of today's and this minute's allowance, or stop. */
async function acquire() {
  if ((await count('day', dayKey())) >= (await dailyLimit())) {
    throw new ZohoBudgetExhausted(await exhaustedMessage());
  }
  const limit = await minuteLimit();
  let waited = 0;
  for (;;) {
    const minute = minuteKey();
    if ((await bump('minute', minute)) <= limit) break;
    await bump('minute', minute, -1);
    const wait = 60 - ist(now()).second + 1;
    if (!isBackground() && waited + wait > 5) {
      throw new ZohoRateLimited(SPEED_LIMIT_MESSAGE);
    }
    waited += wait;
    if (sleeper) await sleeper(wait);
    else await new Promise((r) => setTimeout(r, wait * 1000));
  }
  if ((await bump('day', dayKey())) === 1) {
    // A new day: drop the minute rows of earlier days (the day rows stay for the history).
    await db.execute("DELETE FROM zoho_api_usage WHERE bucket_type = 'minute' AND bucket < :d", { d: dayKey() });
  }
}

/** After a response: Zoho's "too many requests" (HTTP 429, Books codes 44/45, the OAuth "too many requests continuously") stops the run. */
function assertNotRateLimited(status, body) {
  let decoded = null;
  try {
    decoded = JSON.parse(String(body));
  } catch {
    decoded = null;
  }
  const code = decoded && decoded.code != null ? Number(decoded.code) : null;
  const text = decoded && typeof decoded === 'object'
    ? `${decoded.message || ''} ${decoded.error_description || ''} ${decoded.error || ''}`
    : String(body);
  if (Number(status) === 429 || code === 44 || code === 45 || /too many requests|maximum number of api|api (call|request)s? limit/i.test(text)) {
    throw new ZohoRateLimited(RATE_LIMIT_MESSAGE);
  }
}

async function usage() {
  const rows = await db.query(
    "SELECT bucket, calls FROM zoho_api_usage WHERE environment = :e AND bucket_type = 'day' AND bucket >= :since ORDER BY bucket DESC",
    { e: environment(), since: dayKey(now() - 6 * 86400) }
  );
  const today = await count('day', dayKey());
  const daily = await dailyLimit();
  return {
    environment: environment(),
    today,
    minute: await count('minute', minuteKey()),
    daily_limit: daily,
    minute_limit: await minuteLimit(),
    exhausted: today >= daily,
    history: rows.map((r) => ({ day: String(r.bucket), calls: Number(r.calls) })),
  };
}

module.exports = {
  DEFAULT_DAILY, DEFAULT_MINUTE, SPEED_LIMIT_MESSAGE, RATE_LIMIT_MESSAGE,
  ZohoSyncStop, ZohoBudgetExhausted, ZohoRateLimited, rethrow,
  inBackground, setTestLimits, useSettingLimits, setClock, setSleeper,
  dailyLimit, minuteLimit, dayKey, minuteKey, exhaustedMessage, exhaustedToday,
  acquire, assertNotRateLimited, usage,
};
