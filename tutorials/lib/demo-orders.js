'use strict';
// Child-process helper: creates a demo buyer + order (with goods lines) for an associate,
// using the Node app's own test fixtures against the PHP dev database (both stacks share
// one schema). Usage: node demo-orders.js <json>  → prints JSON { clientId, orderId, reference }.
// Every record carries TEST / tutorial wording; videos that use it restore the DB snapshot afterwards.
const path = require('path');

const app = path.resolve(__dirname, '..', '..', 'NODE_MySQL_Version', 'app');
const db = require(path.join(app, 'src/config/db'));
const { createTestClient, createTestOrder } = require(path.join(app, 'tests/support/fixtures'));

async function main() {
  const o = JSON.parse(process.argv[2]);
  const clientId = await createTestClient(o.email);
  await db.execute(
    `UPDATE clients SET company_legal_name = :name, country_of_destination = :country, associate_id = :a,
       associate_registered_on = CURDATE(), associate_locked_until = DATE_ADD(CURDATE(), INTERVAL 6 MONTH), associate_source = :src
     WHERE id = :id`,
    { name: o.company, country: o.country, a: o.associateId, src: o.source || 'link', id: clientId }
  );
  const out = { clientId, orders: [] };
  for (const ord of o.orders || []) {
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', ord.currency || 'USD');
    const ref = ord.reference || `TEST-TUT-${String(orderId).padStart(4, '0')}`;
    // The fixture always uses sequence 1; move each order on so the next one for this buyer fits.
    await db.execute('UPDATE orders SET order_reference = :r, status = :s, sequence_no = :q WHERE id = :id',
      { r: ref, s: ord.status || 'active', q: out.orders.length + 2, id: orderId });
    let n = 0;
    for (const l of ord.lines || [{ description: 'Granite slabs, polished, 20 mm', qty: 300, unit: 'm2', price: 32 }]) {
      n += 1;
      await db.execute(
        `INSERT INTO order_products (order_id, line_no, line_type, description, quantity, unit, unit_price, fob_value, hs_code, is_active)
         VALUES (:o, :n, 'goods', :d, :q, :u, :p, :f, '68022310', 1)`,
        { o: orderId, n, d: l.description, q: l.qty, u: l.unit, p: l.price, f: Math.round(l.qty * l.price * 100) / 100 }
      );
    }
    for (const p of ord.payouts || []) {
      await db.execute(
        `INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, status,
            paid_on, paid_inr, tds_inr, tds_rate, tds_section, paid_reference, due_on, note)
         VALUES (:o, 'associate', :a, 'associate', :inr, :fc, :cur, :m, :st, :paidOn, :paidInr, :tds, :rate, :sec, :ref, :due, :note)`,
        { o: orderId, a: o.associateId, inr: p.inr, fc: p.fc ?? null, cur: ord.currency || 'USD', m: p.month, st: p.status || 'due',
          paidOn: p.paidOn ?? null, paidInr: p.paidInr ?? null, tds: p.tds ?? 0, rate: p.rate ?? null, sec: p.section ?? null,
          ref: p.ref ?? null, due: p.due ?? null, note: p.note ?? null }
      );
    }
    out.orders.push({ orderId, reference: ref });
  }
  console.log(JSON.stringify(out));
}

main().then(() => process.exit(0)).catch((e) => { console.error(e.stack || e.message); process.exit(1); });
