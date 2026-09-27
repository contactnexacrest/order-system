'use strict';

const fs = require('fs');
const env = require('../config/env');
const auditLogRepository = require('../repositories/auditLogRepository');
const mysqlDumpService = require('../services/mysqlDumpService');

/**
 * Port of App\Controllers\DataExportController (PHP). Point 6 — "we will
 * have a migrate data / export data button in our application... visible
 * to role+permission person and of course superadmin always." Gated on
 * the data_export_run permission at route level (see server.js); a Super
 * Admin already gets every permission via sessionAuth's unconditional
 * bypass, so no extra check is needed here.
 *
 * Every export is a real, streamed database dump of the live app
 * database — nothing is faked or sampled — so each run is logged to the
 * audit log (who, when, which file), matching how every other
 * sensitive/irreversible action in this app is tracked.
 */

async function index(req, res) {
  res.renderView('data_export/index', { dbName: env.get('DB_DATABASE') }, 'layout/base');
}

async function downloadSchema(req, res) {
  const tmpPath = await mysqlDumpService.dumpSchema();
  await stream(req, res, tmpPath, 'schema', 'DATA_EXPORT_SCHEMA');
}

async function downloadData(req, res) {
  const tmpPath = await mysqlDumpService.dumpData();
  await stream(req, res, tmpPath, 'data', 'DATA_EXPORT_DATA');
}

async function stream(req, res, tmpPath, kind, auditAction) {
  const user = req.user;
  const timestamp = new Date().toISOString().replace(/[-:]/g, '').replace('T', '-').slice(0, 15);
  const filename = `nexacrest-${kind}-${timestamp}.sql`;

  await auditLogRepository.log(user ? user.id : null, auditAction, 'data_export', null, null, null, filename);

  const stat = fs.statSync(tmpPath);
  res.setHeader('Content-Type', 'application/sql');
  res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);
  res.setHeader('Content-Length', String(stat.size));
  res.setHeader('Cache-Control', 'no-store');

  const readStream = fs.createReadStream(tmpPath);
  readStream.pipe(res);
  readStream.on('close', () => {
    fs.unlink(tmpPath, () => {});
  });
}

module.exports = { index, downloadSchema, downloadData };
