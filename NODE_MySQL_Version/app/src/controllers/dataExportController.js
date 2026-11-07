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
  // QA ACC-18: the data dump holds every division's records, all money and
  // CAFIN — so it also needs those rights, not just data_export_run.
  let permissions = req.permissions;
  if (!permissions) {
    // Called without sessionAuth (a direct call): read the user's rights as PHP's PermissionService::can() does.
    const user = await require('../repositories/userRepository').findById(req.user.id);
    permissions = user ? await require('../services/permissionService').load(user.id, user.role_id, false) : {};
    if (user && (await require('../services/superAdminService').isEffective(user.id))) permissions = { view_cafin: true, see_all_divisions: true };
  }
  if (!permissions.view_cafin || !permissions.see_all_divisions) {
    res.status(403).send('The data export holds every division\'s records, all money and CAFIN: it needs "View & download CAFIN" and "See all divisions" as well.');
    return;
  }
  const tmpPath = await mysqlDumpService.dumpData();
  await stream(req, res, tmpPath, 'data', 'DATA_EXPORT_DATA');
}

async function stream(req, res, tmpPath, kind, auditAction) {
  // The dump is a large temporary file (it can be well over 1 GB): it must be deleted even
  // when the download is cancelled or the connection drops part-way, or each aborted
  // download leaves a full copy of the database on the server's disk.
  let removed = false;
  const remove = () => { if (!removed) { removed = true; fs.unlink(tmpPath, () => {}); } };
  try {
    await streamFile(req, res, tmpPath, kind, auditAction, remove);
  } catch (e) {
    remove();
    throw e;
  }
}

async function streamFile(req, res, tmpPath, kind, auditAction, remove) {
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
  readStream.on('close', remove);
  readStream.on('error', remove);
  // If the browser goes away before the end, stop reading so the file is closed and removed.
  res.on('close', () => { if (!readStream.destroyed) readStream.destroy(); });
  readStream.pipe(res);
}

module.exports = { index, downloadSchema, downloadData, stream };
