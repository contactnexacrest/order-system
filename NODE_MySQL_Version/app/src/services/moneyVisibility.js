'use strict';

// Port of App\Services\MoneyVisibility. Owner decision D-214 (2026-10-31),
// phase 2: the one rule for "may this person, in the department they are
// working as, see this kind of money?". Every page, list, report, document
// download, file download and dossier asks here instead of repeating its own
// check. Sales sees the selling side, Procurement the buying side, Logistics
// only logistics costs; Finance, management, the auditors and the CA see
// everything. Reads req.permissions (already resolved for the department).

const SELLING = 'selling';
const BUYER_PAYMENTS = 'buyer_payments';
const PURCHASE = 'purchase';
const LOGISTICS = 'logistics';

const PERMISSIONS = {
  [SELLING]: 'view_selling_prices',
  [BUYER_PAYMENTS]: 'view_buyer_payments',
  [PURCHASE]: 'view_purchase_prices',
  [LOGISTICS]: 'view_logistics_costs',
};

/** Document type code => the money it carries (types not listed carry none). */
const DOCUMENT_CLASSES = {
  QT: SELLING, BUYERPO: SELLING, PI: SELLING, OC: SELLING,
  FDN: SELLING, CI: SELLING, AMD: SELLING, COOPREP: SELLING, ESI: SELLING,
  CHECKLIST_2_FINANCE: BUYER_PAYMENTS,
  SUPPO: PURCHASE,
  BLI: LOGISTICS,
};

function documentClass(typeCode) {
  return DOCUMENT_CLASSES[String(typeCode || '').toUpperCase()] || null;
}

/** Whether these permissions (req.permissions) show this class of money. */
function can(permissions, cls) {
  return Boolean(permissions && PERMISSIONS[cls] && permissions[PERMISSIONS[cls]]);
}

function canSeeDocument(permissions, typeCode) {
  const cls = documentClass(typeCode);
  return cls === null || can(permissions, cls);
}

/** Order costs and expense receipts: Finance and management only. */
const FINANCIALS = 'financials';

/** Whether these permissions may have this stored file, by the money it shows. */
async function canSeeFile(permissions, fileId) {
  const fileStoreRepository = require('../repositories/fileStoreRepository');
  const cls = await fileStoreRepository.moneyClassFor(fileId);
  if (cls === null) {
    return true;
  }
  if (cls === FINANCIALS) {
    return Boolean(permissions && permissions.manage_order_financials);
  }
  return can(permissions, cls);
}

/** { selling, buyer_payments, purchase, logistics } for views. */
function flags(permissions) {
  const out = {};
  for (const cls of Object.keys(PERMISSIONS)) {
    out[cls] = can(permissions, cls);
  }
  return out;
}

/**
 * Owner decision D-214 (D1–D12): "Logistics sees the client name, Procurement
 * only the order number" — a role tied to a Procurement designation does not
 * see the client name (QA Round 7, R7-N02). Port of MoneyVisibility::isProcurementRole().
 */
async function isProcurementRole(roleId) {
  if (roleId === null || roleId === undefined) return false;
  const db = require('../config/db');
  const row = await db.queryOne(
    "SELECT COUNT(*) AS n FROM department_designations dd JOIN departments d ON d.id = dd.department_id WHERE dd.role_id = :r AND d.code = 'PROC'",
    { r: roleId }
  );
  return Number(row.n) > 0;
}

module.exports = {
  SELLING, BUYER_PAYMENTS, PURCHASE, LOGISTICS, FINANCIALS, PERMISSIONS, DOCUMENT_CLASSES,
  documentClass, can, canSeeDocument, canSeeFile, flags, isProcurementRole,
};
