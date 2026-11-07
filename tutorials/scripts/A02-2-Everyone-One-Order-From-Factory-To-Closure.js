'use strict';
// A02 Part 2 — One order, end to end: from the supplier to closure (Stages 5–9).
// Same order as Part 1 (set up again and moved to Stage 5 off camera, exactly as Part 1 left it).
// On camera: production update, compliance checklist, BL issued, balance cleared, order closed,
// what the buyer and the associate see. Between scenes the routine steps are done off camera.
// Source of truth: docs/APPLICATION_HANDBOOK.md 5.4 (Steps 7–13), SOP 07–13.

const demo = require('../lib/demo');
const A = require('./_a02');

let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
const inDays = (n) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);

module.exports = {
  code: 'A02-2',
  file: 'A02-2-Everyone-One-Order-From-Factory-To-Closure.mp4',
  group: 'Group A · Everyone',
  title: 'One Order, End to End · Part 2',
  subtitle: 'From the supplier\'s factory to a closed order.',
  minutes: '10',
  restoreDb: true,

  async setup(d) {
    const ctx = await A.setup(d);
    order = ctx.orderId;
    A.move(d, 'part1Done', order);
    ctx.actors.client.cookies = await A.clientCookies(d);
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'Welcome back. In Part 1, the buyer confirmed the order. Now we buy the stone, make sure it is inspected and certified, ship it, collect the balance, and close the order. New people join in: Procurement, Export Compliance and Logistics.' },

    // ---------- 1. Supplier ----------
    { card: 'chapter', title: 'Buying from the supplier', subtitle: 'Stage 5 · Supplier PO and production.', say: 'Chapter one. Buying the stone.', do: async () => { A.move(demo, 'supplierPo', order); } },
    { as: 'procurement', goto: at('#step-group-stage-5'), caption: 'Step 7 · Supplier PO', say: 'The Procurement Executive takes over. They saved the supplier purchase order terms, the material, grade, finish, quantity, price and delivery place, and generated the supplier P O. The Managing Director approved it, and the supplier signed and returned it. That passes stage five.', do: async (d) => { await A.openTo(d, '#step-group-stage-5'); await d.point('#step-group-stage-5 > summary', 1500); await d.point('.process-step[data-stage="5"]', 1000); } },
    { caption: 'Internal only', say: 'An important rule: the supplier purchase order is internal. It never goes to the buyer, and the buyer never learns who our supplier is. Procurement sees purchase prices; Sales sees selling prices.', do: async (d) => { await A.openTo(d, '#step-7b-supplier-po-generate > summary'); await d.point('#step-7b-supplier-po-generate > summary', 1800); } },
    { goto: at('#step-production'), caption: 'Step 8 · Production update', say: 'While the factory works, Procurement keeps everyone informed. Step 8: production status, and the estimated shipment date.', do: async (d) => {
      const f = await A.openTo(d, 'form[action$="/production-status"]');
      await d.type(f.locator('[name=production_status_text]'), 'Slabs cut and polished; tiles in production.');
      await d.type(f.locator('[name=est_shipment_date_text]'), 'Ready to ship in about 10 days');
      await f.locator('[name=est_shipment_date]').fill(inDays(10));
    } },
    { caption: 'Update', say: 'Update. The buyer sees the new estimated date in their portal, as a message in the order updates.', do: async (d) => { await d.clickNav(d.page.locator('form[action$="/production-status"] button[type=submit]')); await d.point('.alert', 1500); } },

    // ---------- 2. Freight and compliance ----------
    { card: 'chapter', title: 'Freight and certificates', subtitle: 'Stage 6 · Freight · Compliance before shipment.', say: 'Chapter two. Freight, and paperwork for the authorities.', do: async () => { A.move(demo, 'freight', order); A.move(demo, 'packing', order); } },
    { as: 'logistics', goto: at('#step-group-stage-6'), caption: 'Step 9 · Freight Payment (CIF)', say: 'This is a C I F order, so we arrange and insure the sea freight, and the buyer pays it to us. The Logistics Executive saved the freight terms with the forwarder, generated the freight Debit Note, Accounts recorded the buyer\'s freight payment, and the Finance Manager cleared it. On an F O B order, this whole stage is skipped automatically.', do: async (d) => { await A.openTo(d, '#step-group-stage-6'); await d.point('#step-group-stage-6 > summary', 1500); await d.point('.process-step[data-stage="6"]', 1000); } },
    { as: 'compliance', goto: at('#compliance-checklist-shipment'), caption: 'Compliance — before shipment', say: 'Meanwhile, Export Compliance worked through the checklist before shipment: the fumigation certificate, the phytosanitary certificate, and the pre-shipment inspection, which passed. This checklist never locks other steps, but the bill of lading waits until the inspection has passed.', do: async (d) => { await A.openTo(d, '#compliance-checklist-shipment > p'); await d.point('#compliance-checklist-shipment', 2500); } },

    // ---------- 3. Shipping ----------
    { card: 'chapter', title: 'Packing and shipping', subtitle: 'Stage 7 · Packing list, BL instruction, Bill of Lading.', say: 'Chapter three. The goods leave India.' },
    { as: 'logistics', goto: at('#step-group-stage-7'), caption: 'Step 10 · Packing & BL Instruction', say: 'Logistics recorded the crates, generated the packing list, saved the shipping details, the vessel, voyage and container, and generated the B L instruction sheet for the shipping line. Each was approved. The supplier was paid in full before packing.', do: async (d) => { await A.openTo(d, '#step-group-stage-7'); await d.point('#step-group-stage-7 > summary', 1500); } },
    { caption: 'Step 10f · Confirm BL Issued', say: 'The ship has sailed, and the shipping line issued the bill of lading. Logistics types the B L number, and confirms.', do: async (d) => {
      const f = await A.openTo(d, 'form[action$="/bl-issued"]');
      await d.type(f.locator('[name=bl_number]'), 'TSLU-BL-2611-07');
      await d.clickNav(f.locator('button[type=submit]').last());
    } },
    { caption: 'Stage 8 unlocked', say: 'Bill of lading recorded. Stage seven passes, and stage eight, the commercial invoice and balance, opens.', do: async (d) => { await d.point('.alert', 1500); } },

    // ---------- 4. Invoice and balance ----------
    { card: 'chapter', title: 'The invoice and the balance', subtitle: 'Stage 8 · Commercial Invoice, scanned BL, balance.', say: 'Chapter four. Getting paid in full.', do: async () => { A.move(demo, 'ciBalance', order); } },
    { as: 'client', goto: at().replace('/orders/', '/client/orders/'), caption: 'The buyer\'s documents', say: 'The commercial invoice was generated from the quantities actually shipped, and approved. The scanned bill of lading was sent to the buyer, which starts the clock for the balance. In the portal, the buyer now has the commercial invoice, the packing list and the freight debit note, ready to download.', do: async (d) => { await d.point(d.page.locator('table').filter({ hasText: 'Download PDF' }).first(), 2500); } },
    { as: 'fm', goto: at('#step-group-stage-8'), caption: 'Step 11d · Mark Balance Cleared', say: 'The buyer paid the sixty percent balance. Accounts recorded it from the bank statement, and the Finance Manager confirms it has landed: Mark Balance Cleared.', do: async (d) => {
      const f = await A.openTo(d, 'form[action$="/payment/balance/clear"]');
      await d.clickNav(f.locator('button[type=submit]').last());
    } },
    { caption: 'Stage 9 unlocked', say: 'Balance cleared. Stage eight passes, and the last stage opens: sending the original documents, and closing the order.', do: async (d) => { await d.point('.alert', 1500); } },

    // ---------- 5. Closure ----------
    { card: 'chapter', title: 'Closing the order', subtitle: 'Stage 9 · Original documents to the buyer.', say: 'Chapter five. Closing the order.', do: async () => { A.move(demo, 'closeReady', order); } },
    { as: 'logistics', goto: at('#step-group-stage-9'), caption: 'Step 13 · Documents & Settlement', say: 'Export Compliance prepared the certificate of origin sheet. The original bills of lading came back from the customs agent, and were endorsed. Now Logistics couriers the complete set of original documents to the buyer.', do: async (d) => { await A.openTo(d, '#step-group-stage-9'); await d.point('#step-group-stage-9 > summary', 1500); } },
    { caption: 'Step 13f · Close Order', say: 'They enter the courier tracking number, and click Close Order.', do: async (d) => {
      const f = await A.openTo(d, 'form[action$="/close"]');
      await d.type(f.locator('[name=courier_tracking_number]'), 'TEST-COURIER-0007');
      await d.clickNav(f.locator('button[type=submit]').first());
    } },
    { caption: 'All nine stages done', say: 'And there it is: all nine stages are done, and the buyer now sees the order as complete. The order stays open for the last loose ends: government export benefits, and the final financial review.', do: async (d) => { await d.point('.alert', 1500); await d.scrollTo('.process-stepper'); await d.point('.process-stepper', 1800); } },
    { as: 'associate', goto: '/associate/orders', caption: 'What the associate sees', say: 'And the associate? They followed the order in their own portal the whole time. The buyer has paid in full, so NexaCrest now confirms the associate\'s commission, and it is paid in the next payout run. The associate sees their commission, but never our costs or margins.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: 'Harbourline' }).first(), 2500); } },
    { card: 'list', kicker: 'After closing', title: 'The last steps', items: [
      'Step 12: costs, export benefit eligibility and the profit review (Finance).',
      'Step 13g: government benefits claimed and received.',
      'Step 13h: Mark Fully Settled locks the order for good.',
      'The internal cost sheet (CAFIN) is created then: management only, never the buyer.',
    ], say: 'What is left after closing? Step twelve: Finance enters the order costs, answers which export benefits apply, and reviews the profit. Step thirteen g: the government benefits are claimed and received. And finally, Mark Fully Settled locks the order for good. At that moment the app creates the internal cost sheet, CAFIN, for management only. It never reaches the buyer.' },

    { card: 'list', kicker: 'Recap', title: 'One order, end to end', items: [
      'Procurement: supplier PO and production updates.',
      'Logistics and Compliance: freight, certificates, inspection, packing, Bill of Lading.',
      'Finance: freight and balance cleared, by a different person than who recorded them.',
      'Logistics closes the order; the buyer and the associate see it complete.',
    ], next: 'Group B · Staff videos, one per role', say: 'Let us recap Part 2. Procurement placed the supplier purchase order and kept everyone updated on production. Logistics and Export Compliance handled the freight, the certificates, the inspection, the packing and the bill of lading. Finance cleared the freight and the balance, always a different person from the one who recorded them. And Logistics closed the order, so the buyer and the associate saw it complete. That is one order, end to end, nine stages, many hands, one record. Next, the staff videos go deeper, one role at a time. Thanks for watching!' },
  ]; },
};
