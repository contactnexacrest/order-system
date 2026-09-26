'use strict';

const caRepository = require('../repositories/caRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const zohoBooksService = require('../services/zohoBooksService');

/**
 * CA / Accounting module (Phase 3) — orchestrates a Zoho Books sync run,
 * from either the manual "Sync Now" button (server.js's
 * ca_module_manage-gated route) or the scheduled cron job
 * (cron/zohoSync.js). Deliberately the single place that decides "not
 * configured" is a routine no-op, not an error — see point 5 of the CA
 * module brief: "if zoho credentials are not present, then it shouldn't
 * be a blocker for our application."
 */
async function syncPendingRevenue(triggeredBy, triggeredByUserId) {
  if (!(await zohoBooksService.isEnabled())) {
    await zohoSyncLogRepository.log('revenue_payment', null, null, null, 'skipped', null, 'Zoho Books is not enabled/configured — nothing synced.', triggeredBy, triggeredByUserId);
    return { synced: 0, failed: 0, skipped: 1 };
  }

  let synced = 0;
  let failed = 0;
  for (const leg of await caRepository.legsPendingZohoSync()) {
    const referenceNumber = `${leg.buyer_inquiry_ref}-${leg.leg}`;
    try {
      const zohoReference = await zohoBooksService.pushRevenuePayment(
        leg.client_id,
        leg.company_legal_name,
        leg.inr_actual,
        String(leg.cleared_at).slice(0, 10),
        referenceNumber
      );
      await markSynced(leg.order_id, leg.leg, zohoReference);
      await zohoSyncLogRepository.log('revenue_payment', 'order_payment_status', leg.order_id, leg.leg, 'success', zohoReference, `Pushed as Zoho Books customer payment ${zohoReference}.`, triggeredBy, triggeredByUserId);
      synced += 1;
    } catch (e) {
      await zohoSyncLogRepository.log('revenue_payment', 'order_payment_status', leg.order_id, leg.leg, 'error', null, e.message, triggeredBy, triggeredByUserId);
      failed += 1;
    }
  }

  if (synced === 0 && failed === 0) {
    await zohoSyncLogRepository.log('revenue_payment', null, null, null, 'skipped', null, 'Nothing pending — every recorded INR actual is already synced.', triggeredBy, triggeredByUserId);
    return { synced: 0, failed: 0, skipped: 1 };
  }

  return { synced, failed, skipped: 0 };
}

async function markSynced(orderId, leg, zohoReference) {
  if (leg === 'advance') return orderPaymentStatusRepository.setAdvanceZohoSync(orderId, zohoReference);
  if (leg === 'balance') return orderPaymentStatusRepository.setBalanceZohoSync(orderId, zohoReference);
  if (leg === 'freight') return orderPaymentStatusRepository.setFreightZohoSync(orderId, zohoReference);
  return undefined;
}

module.exports = { syncPendingRevenue };
