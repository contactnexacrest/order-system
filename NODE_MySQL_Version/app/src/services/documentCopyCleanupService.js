'use strict';

const fs = require('fs');
const db = require('../config/db');
const financialYear = require('../helpers/financialYear');
const auditLogRepository = require('../repositories/auditLogRepository');
const caFyLockRepository = require('../repositories/caFyLockRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');

// Port of App\Services\DocumentCopyCleanupService — owner points 5 / 8 / 9
// (2 Nov 2026): Admin → Settings → Document copies clean-up. Same rules,
// same order, same wording; see the PHP class for the full explanation.
//
// Rules (each only when switched on): draft — the DRAFT copy of a revision
// that has since been approved, N days after approval; superseded — every
// copy of a superseded revision NEVER sent to the client, N days after it was
// superseded, except its SUPERSEDED-stamped copy; cafin_old — every CAFIN copy
// except the latest per order, 30 days after a newer one was made.
// Never removed: anything sent to the client, the current copy of every
// revision, the Word copy of a current revision, uploaded files, files of an
// order with an open dispute, documents in a CA-locked financial year.

const KEY_DRAFT = 'doc_cleanup_draft_days';
const KEY_SUPERSEDED = 'doc_cleanup_superseded_days';
const KEY_CAFIN = 'doc_cleanup_cafin_mode';
const DRAFT_OPTIONS = [0, 30, 90, 180];
const SUPERSEDED_OPTIONS = [0, 90, 180, 365];
const CAFIN_OPTIONS = ['keep_all', 'latest_only'];
const CAFIN_DAYS = 30;
const REASONS = {
  draft: 'Draft copy of an approved document',
  superseded: 'Copy of a superseded revision that was never sent to the client',
  cafin_old: 'Older CAFIN copy (a newer one exists)',
};

async function settings() {
  const draft = Number((await companySettingsRepository.get(KEY_DRAFT)) ?? '0');
  const superseded = Number((await companySettingsRepository.get(KEY_SUPERSEDED)) ?? '0');
  const cafin = String((await companySettingsRepository.get(KEY_CAFIN)) ?? 'keep_all');
  return {
    draft_days: DRAFT_OPTIONS.includes(draft) ? draft : 0,
    superseded_days: SUPERSEDED_OPTIONS.includes(superseded) ? superseded : 0,
    cafin_mode: CAFIN_OPTIONS.includes(cafin) ? cafin : 'keep_all',
  };
}

function anyOn(s) {
  return s.draft_days > 0 || s.superseded_days > 0 || s.cafin_mode === 'latest_only';
}

const GUARDS = `fs.file_origin = 'GENERATED_AUTO' AND fs.is_active = 1 AND fs.sent_to_client = 0
                AND NOT EXISTS (SELECT 1 FROM disputes dp WHERE dp.order_id = d.order_id AND dp.status <> 'Resolved' AND dp.resolved_at IS NULL)`;
const SELECT = `SELECT fs.id AS file_id, fs.original_filename, fs.file_size_bytes, fs.server_path, fs.uploaded_at,
                       d.id AS document_id, d.order_id, d.document_reference, d.revision_number, d.generated_at, dt.code AS document_type_code
                  FROM file_store fs
                  JOIN documents d ON d.id = fs.linked_document_id
                  JOIN document_types dt ON dt.id = d.document_type_id`;
// A revision counts as sent however it went: a sent e-mail, "counted as sent" on approval with
// e-mail off, or any of its files flagged sent (portal download included).
const NEVER_SENT = `NOT EXISTS (SELECT 1 FROM email_log el WHERE el.document_id = d.id AND el.status = 'sent')
                    AND NOT EXISTS (SELECT 1 FROM audit_log al WHERE al.entity_type = 'documents' AND al.entity_id = d.id
                                     AND al.action_type = 'DOCUMENT_MARKED_SENT_MAIL_DISABLED')
                    AND NOT EXISTS (SELECT 1 FROM file_store s2 WHERE s2.linked_document_id = d.id AND s2.sent_to_client = 1)`;

/** Every file the given settings would remove right now — the same list drives the preview and the run. */
async function candidates(s = null) {
  s = s || (await settings());
  const byFile = new Map();
  const add = (rows, rule) => {
    for (const row of rows) {
      if (!byFile.has(Number(row.file_id))) byFile.set(Number(row.file_id), { ...row, rule });
    }
  };

  if (s.draft_days > 0) {
    add(await db.query(`${SELECT}
         JOIN file_store cur ON cur.id = d.pdf_file_id
        WHERE ${GUARDS}
          AND dt.code <> 'CAFIN'
          AND d.status IN ('approved', 'sent', 'superseded')
          AND fs.id <> d.pdf_file_id
          AND fs.mime_type = 'application/pdf'
          AND fs.original_filename NOT LIKE '%(SUPERSEDED)%'
          AND fs.id = (SELECT MIN(f2.id) FROM file_store f2
                        WHERE f2.linked_document_id = d.id AND f2.file_origin = 'GENERATED_AUTO' AND f2.mime_type = 'application/pdf')
          AND cur.uploaded_at <= DATE_SUB(NOW(), INTERVAL :days DAY)`, { days: s.draft_days }), 'draft');
  }
  if (s.superseded_days > 0) {
    add(await db.query(`${SELECT}
         JOIN file_store cur ON cur.id = d.pdf_file_id
        WHERE ${GUARDS}
          AND dt.code <> 'CAFIN'
          AND d.status = 'superseded'
          AND fs.id <> d.pdf_file_id
          AND ${NEVER_SENT}
          AND cur.uploaded_at <= DATE_SUB(NOW(), INTERVAL :days DAY)`, { days: s.superseded_days }), 'superseded');
  }
  if (s.cafin_mode === 'latest_only') {
    add(await db.query(`${SELECT}
        WHERE ${GUARDS}
          AND dt.code = 'CAFIN'
          AND EXISTS (SELECT 1 FROM documents n
                       WHERE n.order_id = d.order_id AND n.document_type_id = d.document_type_id
                         AND n.id > d.id AND n.generated_at <= DATE_SUB(NOW(), INTERVAL :days DAY))`, { days: CAFIN_DAYS }), 'cafin_old');
  }

  const locked = await caFyLockRepository.lockedYears();
  const rows = [...byFile.values()]
    .filter((r) => locked.length === 0 || !locked.includes(financialYear.label(r.generated_at)))
    .sort((a, b) => Number(a.file_id) - Number(b.file_id));
  for (const r of rows) r.reason = REASONS[r.rule];
  return rows;
}

/** Per rule: what the current settings would remove, or — for a rule that is off — what its first option would. */
async function estimate(s = null) {
  s = s || (await settings());
  const probe = {
    draft: { draft_days: s.draft_days || DRAFT_OPTIONS[1], superseded_days: 0, cafin_mode: 'keep_all' },
    superseded: { draft_days: 0, superseded_days: s.superseded_days || SUPERSEDED_OPTIONS[1], cafin_mode: 'keep_all' },
    cafin_old: { draft_days: 0, superseded_days: 0, cafin_mode: 'latest_only' },
  };
  const isOn = { draft: s.draft_days > 0, superseded: s.superseded_days > 0, cafin_old: s.cafin_mode === 'latest_only' };
  const out = {};
  for (const [rule, ps] of Object.entries(probe)) {
    const rows = await candidates(ps);
    out[rule] = {
      files: rows.length,
      bytes: rows.reduce((t, r) => t + Number(r.file_size_bytes), 0),
      hypothetical: !isOn[rule],
    };
  }
  return out;
}

function formatBytes(bytes) {
  const n = (v, d) => v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
  if (bytes >= 1073741824) return `${n(bytes / 1073741824, 2)} GB`;
  if (bytes >= 1048576) return `${n(bytes / 1048576, 1)} MB`;
  return `${n(bytes / 1024, 0)} KB`;
}

/** Removes every candidate (the file, and the row marked inactive with when and why), with audit rows. */
async function run(userId, trigger) {
  const s = await settings();
  if (!anyOn(s)) return { files: 0, bytes: 0, missing: 0, skipped_off: true };
  let files = 0;
  let bytes = 0;
  let missing = 0;
  for (const row of await candidates(s)) {
    const p = String(row.server_path || '');
    if (p !== '' && fs.existsSync(p)) {
      try {
        fs.unlinkSync(p);
      } catch (e) {
        continue; // could not delete the file — leave the row as it is, try again next run
      }
    } else {
      missing++;
    }
    await db.execute(
      'UPDATE file_store SET is_active = 0, removed_at = NOW(), removed_reason = :reason WHERE id = :id AND is_active = 1',
      { reason: row.reason, id: Number(row.file_id) }
    );
    const size = Number(row.file_size_bytes);
    await auditLogRepository.log(
      userId, 'DOCUMENT_COPY_REMOVED', 'file_store', Number(row.file_id), 'file', String(row.original_filename), null,
      `${row.reason} — ${row.document_type_code} ${row.document_reference ?? '—'} (revision ${Number(row.revision_number)}), ${formatBytes(size)}`
    );
    files++;
    bytes += size;
  }
  await auditLogRepository.log(
    userId, 'DOCUMENT_COPY_CLEANUP_RUN', 'company_settings', null, 'document_cleanup', null,
    `${files} file(s), ${formatBytes(bytes)}`,
    `${trigger === 'scheduled' ? 'Daily' : 'Manual'} run — drafts: ${s.draft_days > 0 ? `after ${s.draft_days} days` : 'off'}; `
      + `superseded never sent: ${s.superseded_days > 0 ? `after ${s.superseded_days} days` : 'off'}; `
      + `old CAFIN: ${s.cafin_mode === 'latest_only' ? 'latest only' : 'keep all'}`
  );
  return { files, bytes, missing, skipped_off: false };
}

module.exports = {
  KEY_DRAFT, KEY_SUPERSEDED, KEY_CAFIN, DRAFT_OPTIONS, SUPERSEDED_OPTIONS, CAFIN_OPTIONS, CAFIN_DAYS, REASONS,
  settings, anyOn, candidates, estimate, run, formatBytes,
};
