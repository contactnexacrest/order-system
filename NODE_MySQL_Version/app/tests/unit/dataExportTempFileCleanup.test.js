'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { PassThrough } = require('stream');

jest.mock('../../src/repositories/auditLogRepository', () => ({ log: jest.fn().mockResolvedValue(undefined) }));
const { stream } = require('../../src/controllers/dataExportController');

/**
 * The data export writes the whole database to a temporary file (well over 1 GB on a busy
 * system). It must be deleted even when the browser goes away part-way; before this fix an
 * aborted download left the file behind and filled the server's disk.
 */
function tmpFile() {
  const p = path.join(os.tmpdir(), `nexacrest_dump_test_${process.pid}_${Date.now()}_${Math.random().toString(36).slice(2)}.sql`);
  fs.writeFileSync(p, 'x'.repeat(5 * 1024 * 1024));
  return p;
}
function fakeRes() {
  const res = new PassThrough();
  res.setHeader = () => {};
  return res;
}
const gone = (p) => new Promise((resolve) => {
  const t = setInterval(() => { if (!fs.existsSync(p)) { clearInterval(t); resolve(true); } }, 20);
  setTimeout(() => { clearInterval(t); resolve(!fs.existsSync(p)); }, 3000);
});

describe('data export temporary file is always removed', () => {
  it('after a complete download', async () => {
    const p = tmpFile();
    const res = fakeRes();
    res.resume();
    await stream({ user: { id: 1 } }, res, p, 'data', 'DATA_EXPORT_DATA');
    expect(await gone(p)).toBe(true);
  });

  it('when the browser disconnects part-way', async () => {
    const p = tmpFile();
    const res = fakeRes();
    await stream({ user: { id: 1 } }, res, p, 'data', 'DATA_EXPORT_DATA');
    res.destroy(); // nothing read: the download was cancelled
    expect(await gone(p)).toBe(true);
  });
});
