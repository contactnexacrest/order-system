'use strict';

const db = require('../config/db');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * Port of App\Services\EmailPolicy — owner decision D-280 (11 Nov 2026, point 3):
 * Admin › E-mail settings.
 *
 * Every e-mail the app sends has a kind (one row on the settings page) and an
 * audience (Staff, Associate, Buyer, Supplier). Each kind × audience is
 *   - MUST: always sent, cannot be switched off (log-in and account access);
 *   - SHOULD: on when installed, can be switched off;
 *   - OPTIONAL: off when installed, can be switched on.
 * The switches live in the email_settings table (MUST rows are never read).
 *
 * "Testing mode — hold e-mails to buyers and suppliers" (company setting
 * email_testing_mode, replaces the old mail_sending_enabled master switch)
 * holds only buyer and supplier e-mails that are not MUST. Staff and associate
 * e-mails, and every MUST e-mail, still go out.
 */

const AUDIENCES = { staff: 'Staff', associate: 'Associate', buyer: 'Buyer', supplier: 'Supplier' };

const GROUPS = {
  A: 'Log-in and account access',
  B: 'Orders and documents (to the buyer and the supplier)',
  C: 'Associate programme',
  D: 'Staff — approvals, tasks and alerts',
};

const KINDS = {
  // A. Log-in and account access — all MUST.
  login_code: { group: 'A', label: '6-digit login code', audiences: { staff: 'must', associate: 'must', buyer: 'must' } },
  set_password_invite: { group: 'A', label: 'Set your password (portal opened / registration accepted)', audiences: { associate: 'must', buyer: 'must' }, access_critical: true },
  forgot_password: { group: 'A', label: 'Forgot password — reset link', audiences: { staff: 'must', associate: 'must', buyer: 'must' } },
  bank_details_changed: { group: 'A', label: 'Bank details changed — "if this wasn\'t you, tell us"', audiences: { associate: 'must' } },
  backup_code_alert: { group: 'A', label: 'Backup code used — security alert', audiences: { staff: 'must' } },
  quote_verification_code: { group: 'A', label: 'Quotation request — e-mail verification code', audiences: { buyer: 'must' } },

  // B. Orders and documents.
  buyer_documents: { group: 'B', label: 'Documents: Quotation, Proforma, OC, Commercial Invoice, Debit Note, Packing List … (still need Level-2 e-mail approval)', audiences: { associate: 'optional', buyer: 'should' } },
  buyer_links: { group: 'B', label: 'Links to fill in: quotation correction, PI-stage details, acknowledge OC / upload PO, payment details', audiences: { buyer: 'should' } },
  buyer_messages: { group: 'B', label: 'Order messages and complaint replies', audiences: { buyer: 'should' } },
  buyer_followups: { group: 'B', label: 'Payment follow-up, shipment readiness, BL copy, balance received', audiences: { buyer: 'should' } },
  supplier_links: { group: 'B', label: 'Supplier / logistics partner: upload signed PO, confirm company and bank details', audiences: { supplier: 'should' } },

  // C. Associate programme — all SHOULD.
  associate_clients: { group: 'C', label: 'A client was accepted / not accepted, moved to or from you, an order created for your client', audiences: { associate: 'should' } },
  associate_commission: { group: 'C', label: 'Commission earned, payout held / released / paid, adjustment', audiences: { associate: 'should' } },
  associate_kyc_price_message: { group: 'C', label: 'KYC document accepted / rejected, price ready, message from NexaCrest, new bank details not accepted', audiences: { associate: 'should' } },
  associate_registration_declined: { group: 'C', label: 'Registration declined', audiences: { associate: 'should' } },
  associate_code_status: { group: 'C', label: 'Code / status changed, reminders (protection ending, registration lapsed, agreement ending)', audiences: { associate: 'should' } },

  // D. Staff — all SHOULD.
  staff_approvals: { group: 'D', label: 'Something waits for my approval (price, payment terms, e-mail, document, bank check, cancellation, amendment …) — one e-mail per approval', audiences: { staff: 'should' } },
  staff_counterparty: { group: 'D', label: 'Buyer / supplier / associate did something (paid, uploaded PO, message, registration …) — to the department on the order', audiences: { staff: 'should' } },
  staff_compliance: { group: 'D', label: 'Compliance and deadline alerts (LUT, RCMC, LC dates, overdue payments, eBRC, ECGC, TDS …)', audiences: { staff: 'should' } },
  staff_zoho: { group: 'D', label: 'Zoho sync failures', audiences: { staff: 'should' } },
  staff_daily_summary: { group: 'D', label: 'Daily summary ("your day")', audiences: { staff: 'should' } },
  staff_assigned: { group: 'D', label: 'Order assigned to me / I became department owner', audiences: { staff: 'should' } },
  staff_other: { group: 'D', label: 'Answers to my own requests (approved, rejected, cancelled) and other notices', audiences: { staff: 'should' } },
};

/** Staff notification type (notifications.type) => e-mail kind. Not listed: 'staff_other'. */
const NOTIFICATION_KINDS = {
  payment_terms_approval_needed: 'staff_approvals', order_cancellation_requested: 'staff_approvals',
  amendment_pending_md_approval: 'staff_approvals', email_send_pending_approval: 'staff_approvals',
  line_price_approval: 'staff_approvals', price_request: 'staff_approvals', associate_bank_change: 'staff_approvals',
  document_review_assigned: 'staff_approvals', review_assigned: 'staff_approvals',
  buyer_oc_acknowledged: 'staff_counterparty', buyer_po_uploaded_by_client: 'staff_counterparty',
  client_payment_reported: 'staff_counterparty', supplier_po_ack_uploaded_by_supplier: 'staff_counterparty',
  order_comment_from_client: 'staff_counterparty', dispute_raised: 'staff_counterparty',
  order_feedback_submitted: 'staff_counterparty', reorder_request_submitted: 'staff_counterparty',
  pi_intake_submitted: 'staff_counterparty', client_intake_submitted: 'staff_counterparty',
  client_intake_updated: 'staff_counterparty', associate_message: 'staff_counterparty',
  associate_document_uploaded: 'staff_counterparty', associate_registration: 'staff_counterparty',
  complaint_raised: 'staff_counterparty', inspection_failed: 'staff_counterparty',
  lut_expiry: 'staff_compliance', lut_escalation: 'staff_compliance', rcmc_expiry: 'staff_compliance',
  rcmc_escalation: 'staff_compliance', fdn_overdue: 'staff_compliance', balance_overdue: 'staff_compliance',
  dispute_overdue: 'staff_compliance', quotation_expired: 'staff_compliance', pi_expired: 'staff_compliance',
  pre_shipment_inspection_reminder: 'staff_compliance', agreement_expiry: 'staff_compliance',
  merchant_trade_rbi: 'staff_compliance', ebrc_missing_monthly: 'staff_compliance',
  ecgc_declaration_due: 'staff_compliance', ecgc_declaration_overdue: 'staff_compliance',
  ecgc_policy_expiring: 'staff_compliance', benefit_claim_deadline: 'staff_compliance',
  associate_tds_deposit: 'staff_compliance', associate_tds_26q: 'staff_compliance',
  lc_expiry_soon: 'staff_compliance', lc_last_shipment_soon: 'staff_compliance', lc_presentation_soon: 'staff_compliance',
  zoho_sync_failed: 'staff_zoho',
  order_department_owner: 'staff_assigned', order_assigned: 'staff_assigned',
  backup_code_used: 'backup_code_alert',
};

/** Associate notice event => e-mail kind (associateService.notify). */
const ASSOCIATE_EVENT_KINDS = {
  bank_changed: 'bank_details_changed',
  client_accepted: 'associate_clients', client_not_accepted: 'associate_clients', order_created: 'associate_clients',
  direct_contact: 'associate_clients', link_changed: 'associate_clients',
  commission_earned: 'associate_commission', payout_held: 'associate_commission', payout_released: 'associate_commission',
  payout_paid: 'associate_commission', clawback: 'associate_commission',
  document_added: 'associate_kyc_price_message', document_accepted: 'associate_kyc_price_message',
  document_rejected: 'associate_kyc_price_message', price_ready: 'associate_kyc_price_message',
  message: 'associate_kyc_price_message', bank_rejected: 'associate_kyc_price_message',
  code_changed: 'associate_code_status', status_changed: 'associate_code_status', lock_ending: 'associate_code_status',
  registration_lapsed: 'associate_code_status', agreement_ending: 'associate_code_status',
  portal_invite: 'set_password_invite',
};

/** Generic buyer e-mail templates (no document) => kind. */
const TEMPLATE_KINDS = {
  payment_followup: 'buyer_followups', shipment_readiness: 'buyer_followups',
  bl_copy_sent: 'buyer_followups', balance_receipt_confirmation: 'buyer_followups',
};

const state = { switches: null };

function resetCache() {
  state.switches = null;
}

function kindForNotification(type) {
  return NOTIFICATION_KINDS[type] || 'staff_other';
}

function kindForAssociateEvent(event) {
  return ASSOCIATE_EVENT_KINDS[event] || 'associate_kyc_price_message';
}

function tier(kind, audience) {
  return (KINDS[kind] && KINDS[kind].audiences[audience]) || null;
}

function isMust(kind, audience) {
  return tier(kind, audience) === 'must';
}

async function testingMode() {
  return String(await companySettingsRepository.get('email_testing_mode')) === '1';
}

/** The switch for one kind × audience: MUST is always on; a missing row falls back to its tier's default. */
async function switchOn(kind, audience) {
  const t = tier(kind, audience);
  if (t === null) return false;
  if (t === 'must') return true;
  if (state.switches === null) {
    const map = {};
    try {
      for (const r of await db.query('SELECT email_kind, audience, is_enabled FROM email_settings')) {
        map[`${r.email_kind}|${r.audience}`] = Number(r.is_enabled) === 1;
      }
    } catch (e) {
      // Table not there yet (a database from before D-280): the tier defaults apply.
    }
    state.switches = map;
  }
  const key = `${kind}|${audience}`;
  return Object.prototype.hasOwnProperty.call(state.switches, key) ? state.switches[key] : t === 'should';
}

/** Why this e-mail would NOT be sent — null when it goes out. */
async function holdReason(kind, audience) {
  if (tier(kind, audience) === null) {
    return `this e-mail is not set up for ${String(AUDIENCES[audience] || audience).toLowerCase()}s`;
  }
  if (isMust(kind, audience)) return null;
  if ((audience === 'buyer' || audience === 'supplier') && (await testingMode())) {
    return 'testing mode holds e-mails to buyers and suppliers';
  }
  if (!(await switchOn(kind, audience))) {
    return 'this e-mail is switched off in Admin › E-mail settings';
  }
  return null;
}

async function allows(kind, audience) {
  return (await holdReason(kind, audience)) === null;
}

async function notSentReason(kind, audience) {
  return (await holdReason(kind, audience)) || 'the e-mail could not be sent';
}

/** @returns {Promise<object>} kind => audience => {tier, on}, for the settings page */
async function matrix() {
  const out = {};
  for (const [kind, def] of Object.entries(KINDS)) {
    out[kind] = {};
    for (const [audience, t] of Object.entries(def.audiences)) {
      out[kind][audience] = { tier: t, on: await switchOn(kind, audience) };
    }
  }
  return out;
}

/** Saves the page: `on` = object of "kind|audience" keys ticked. Returns the list of changes. */
async function save(on, testing, userId, reason) {
  const changes = [];
  for (const [kind, def] of Object.entries(KINDS)) {
    for (const [audience, t] of Object.entries(def.audiences)) {
      if (t === 'must') continue;
      const next = Object.prototype.hasOwnProperty.call(on || {}, `${kind}|${audience}`);
      const old = await switchOn(kind, audience);
      if (next !== old) {
        changes.push(`${def.label} — ${AUDIENCES[audience]}: ${next ? 'on' : 'off'}`);
        await db.execute(
          `INSERT INTO email_settings (email_kind, audience, is_enabled, updated_by) VALUES (:k, :a, :e, :u)
           ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by)`,
          { k: kind, a: audience, e: next ? 1 : 0, u: userId }
        );
        await auditLogRepository.log(userId, 'EMAIL_SETTING_CHANGED', 'email_settings', null, `${kind}|${audience}`, old ? '1' : '0', next ? '1' : '0', reason);
      }
    }
  }
  if (!!testing !== (await testingMode())) {
    await companySettingsRepository.set('email_testing_mode', testing ? '1' : '0', userId);
    await auditLogRepository.log(userId, 'EMAIL_TESTING_MODE_CHANGED', 'company_settings', null, 'email_testing_mode', testing ? '0' : '1', testing ? '1' : '0', reason);
    changes.push(`Testing mode (hold e-mails to buyers and suppliers): ${testing ? 'on' : 'off'}`);
  }
  resetCache();
  return changes;
}

/** Tests: sets one switch in the table (as the settings page would) and clears the cache. */
async function setSwitchForTest(kind, audience, on) {
  await db.execute(
    'INSERT INTO email_settings (email_kind, audience, is_enabled) VALUES (:k, :a, :e) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)',
    { k: kind, a: audience, e: on ? 1 : 0 }
  );
  resetCache();
}

module.exports = {
  AUDIENCES, GROUPS, KINDS, NOTIFICATION_KINDS, ASSOCIATE_EVENT_KINDS, TEMPLATE_KINDS,
  resetCache, kindForNotification, kindForAssociateEvent, tier, isMust, testingMode, switchOn,
  holdReason, allows, notSentReason, matrix, save, setSwitchForTest,
};
