'use strict';

/**
 * Owner decision 2 Nov 2026 (D-238, "Zoho clients/products yes"): clients
 * and the products of the Products menu kept in Zoho Books on their own,
 * each behind its own switch (Settings → Zoho Books: zoho_sync_clients,
 * zoho_sync_products — both off by default).
 *
 * One way, app → Books: a record is pushed when it is new or changed in the
 * app (a hash of what was last sent), so an edit made in Books is put back
 * the next time the app's record changes — or for every record at once with
 * "Re-push all" on the Zoho Sync page. A client or product no longer active
 * (or a product deleted) is marked inactive in Books, never deleted.
 * Runs inside the hourly sync and Sync now (not "Sync this order").
 *
 * Clients: only once the client's currency is known (the currency of its
 * latest order) — a Books customer has one currency for good.
 * Products: rate 0 — the catalogue holds sourcing costs only, never a
 * selling price, and no cost is ever sent to Books.
 * PHP twin: ZohoMasterDataSync.php.
 */

const zohoApiBudget = require('./zohoApiBudget');
const crypto = require('crypto');
const db = require('../config/db');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const zohoBooksService = require('./zohoBooksService');
const zohoGuard = require('./zohoGuard');

async function clientsOn() {
  return (await companySettingsRepository.get('zoho_sync_clients')) === '1';
}

async function productsOn() {
  return (await companySettingsRepository.get('zoho_sync_products')) === '1';
}

function sha(value) {
  return crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
}

function clientHash(c) {
  return sha([zohoBooksService.customerPayload(c, false), Number(c.is_active)]);
}

function productHash(p) {
  return sha([zohoBooksService.itemPayload(p, true), Number(p.is_active)]);
}

/** Clients whose Books customer is missing or out of date. */
async function clientsToPush() {
  const rows = await db.query(
    // D-257 Zoho (ZOH-6): only export orders set the currency of the client's (overseas) Books customer;
    // a Domestic Trade / local-sale buyer gets its own INR, GST customer from the tax invoice (zoho_contact_id_inr).
    `SELECT c.*, (SELECT o.currency_code FROM orders o WHERE o.client_id = c.id AND o.currency_code IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM domestic_orders d WHERE d.order_id = o.id) ORDER BY o.id DESC LIMIT 1) AS order_currency
       FROM clients c
      WHERE COALESCE(c.is_sample_data, 0) = 0 AND c.duplicate_of_client_id IS NULL
      ORDER BY c.id`
  );
  const out = [];
  for (const c of rows) {
    if (!zohoGuard.isProduction() && !String(c.company_legal_name).startsWith(zohoGuard.TEST_PREFIX)) continue; // test organisation: only TEST- customers
    const active = Number(c.is_active) === 1;
    if (active && !c.order_currency) continue; // waits for its first order to set the currency
    if (!active && !c.zoho_contact_id) continue; // never in Books — nothing to switch off
    c._hash = clientHash(c);
    if (c.zoho_synced_hash !== c._hash || (active && !c.zoho_contact_id)) out.push(c);
  }
  return out;
}

async function productsToPush() {
  const out = [];
  for (const p of await db.query('SELECT * FROM catalog_products ORDER BY id')) {
    if (!zohoGuard.isProduction() && !String(p.name).startsWith(zohoGuard.TEST_PREFIX)) continue;
    const active = Number(p.is_active) === 1;
    if (!active && !p.zoho_item_id) continue;
    p._hash = productHash(p);
    if (p.zoho_synced_hash !== p._hash || (active && !p.zoho_item_id)) out.push(p);
  }
  return out;
}

async function deletedProducts() {
  return db.query("SELECT id, zoho_id, name FROM zoho_pending_inactivations WHERE kind = 'item' ORDER BY id");
}

/** Called just before a product is deleted: remember its Books item so the next sync marks it inactive. */
async function rememberDeletedProduct(product) {
  if (!product || !product.zoho_item_id) return;
  await db.execute("INSERT INTO zoho_pending_inactivations (kind, zoho_id, name) VALUES ('item', :z, :n)", { z: String(product.zoho_item_id), n: String(product.name).slice(0, 255) });
}

/** "Re-push all": every client and product is sent again at the next sync. */
async function forgetHashes() {
  await db.execute('UPDATE clients SET zoho_synced_hash = NULL');
  await db.execute('UPDATE catalog_products SET zoho_synced_hash = NULL');
}

/** What the next sync would send, per switch that is on. */
async function pendingCounts() {
  return {
    client: (await clientsOn()) ? (await clientsToPush()).length : 0,
    product: (await productsOn()) ? (await productsToPush()).length + (await deletedProducts()).length : 0,
  };
}

async function markClient(id, zohoId, hash) {
  await db.execute('UPDATE clients SET zoho_contact_id = COALESCE(:z, zoho_contact_id), zoho_synced_hash = :h, zoho_synced_at = NOW() WHERE id = :id', { z: zohoId, h: hash, id });
}

async function markProduct(id, zohoId, hash) {
  await db.execute('UPDATE catalog_products SET zoho_item_id = COALESCE(:z, zoho_item_id), zoho_synced_hash = :h, zoho_synced_at = NOW() WHERE id = :id', { z: zohoId, h: hash, id });
}

const VERB = { created: 'Created', linked: 'Linked the existing', updated: 'Updated' };

async function pushClient(c, triggeredBy, userId, result) {
  const id = Number(c.id);
  const zohoId = c.zoho_contact_id ? String(c.zoho_contact_id) : null;
  try {
    if (Number(c.is_active) !== 1) {
      await zohoBooksService.markInactive('contact', zohoId);
      await markClient(id, zohoId, c._hash);
      await zohoSyncLogRepository.log('client', 'client', id, null, 'success', zohoId, `Marked Zoho Books customer "${c.company_legal_name}" inactive: the client is no longer active in the app.`, triggeredBy, userId);
      result.inactivated += 1;
      return;
    }
    const r = await zohoBooksService.pushCustomerContact(c, zohoId, String(c.order_currency));
    await markClient(id, r.contact_id, c._hash);
    await zohoSyncLogRepository.log('client', 'client', id, null, 'success', r.contact_id, `${VERB[r.action]} Zoho Books customer "${c.company_legal_name}" from the client record.`, triggeredBy, userId);
    result.clients += 1;
  } catch (e) {
    zohoApiBudget.rethrow(e, result); // D-239: a spent allowance / rate limit stops the whole run
    await zohoSyncLogRepository.log('client', 'client', id, null, 'error', zohoId, `Client "${c.company_legal_name}": ${e.message}`, triggeredBy, userId);
    result.failed += 1;
  }
}

async function pushProduct(p, triggeredBy, userId, result) {
  const id = Number(p.id);
  const zohoId = p.zoho_item_id ? String(p.zoho_item_id) : null;
  try {
    if (Number(p.is_active) !== 1) {
      await zohoBooksService.markInactive('item', zohoId);
      await markProduct(id, zohoId, p._hash);
      await zohoSyncLogRepository.log('product', 'catalog_product', id, null, 'success', zohoId, `Marked Zoho Books item "${p.name}" inactive: the product is no longer active in the Products menu.`, triggeredBy, userId);
      result.inactivated += 1;
      return;
    }
    const r = await zohoBooksService.pushItem(p, zohoId);
    await markProduct(id, r.item_id, p._hash);
    await zohoSyncLogRepository.log('product', 'catalog_product', id, null, 'success', r.item_id, `${VERB[r.action]} Zoho Books item "${p.name}" from the Products menu.`, triggeredBy, userId);
    result.products += 1;
  } catch (e) {
    zohoApiBudget.rethrow(e, result); // D-239: a spent allowance / rate limit stops the whole run
    await zohoSyncLogRepository.log('product', 'catalog_product', id, null, 'error', zohoId, `Product "${p.name}": ${e.message}`, triggeredBy, userId);
    result.failed += 1;
  }
}

/** @return {Promise<{clients:number, products:number, inactivated:number, failed:number, skipped:number}>} */
async function run(triggeredBy, userId) {
  const result = { clients: 0, products: 0, inactivated: 0, failed: 0, skipped: 0 };
  const cOn = await clientsOn();
  const pOn = await productsOn();
  if (!cOn && !pOn) return result;
  if (!(await zohoBooksService.isEnabled())) {
    result.skipped = 1;
    return result;
  }
  if (cOn) {
    for (const c of await clientsToPush()) await pushClient(c, triggeredBy, userId, result);
  }
  if (pOn) {
    for (const p of await productsToPush()) await pushProduct(p, triggeredBy, userId, result);
    for (const t of await deletedProducts()) {
      try {
        await zohoBooksService.markInactive('item', String(t.zoho_id));
        await db.execute('DELETE FROM zoho_pending_inactivations WHERE id = :id', { id: t.id });
        await zohoSyncLogRepository.log('product', 'catalog_product', null, null, 'success', String(t.zoho_id), `Marked Zoho Books item "${t.name}" inactive: the product was deleted from the Products menu.`, triggeredBy, userId);
        result.inactivated += 1;
      } catch (e) {
        zohoApiBudget.rethrow(e, result); // D-239: a spent allowance / rate limit stops the whole run
        await zohoSyncLogRepository.log('product', 'catalog_product', null, null, 'error', String(t.zoho_id), e.message, triggeredBy, userId);
        result.failed += 1;
      }
    }
  }
  return result;
}

module.exports = {
  clientsOn, productsOn, run, clientsToPush, productsToPush, deletedProducts, rememberDeletedProduct, forgetHashes, pendingCounts, clientHash, productHash,
};
