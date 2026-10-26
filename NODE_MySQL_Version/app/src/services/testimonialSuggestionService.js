'use strict';

const { crc32 } = require('zlib');
const db = require('../config/db');
const orderRepository = require('../repositories/orderRepository');
const orderProductRepository = require('../repositories/orderProductRepository');

/**
 * Port of App\Services\TestimonialSuggestionService. Owner request
 * (2026-10-26): the order-feedback comment box starts with a suggested
 * testimonial the client can keep or edit — written from this order's own
 * details (product, quantity, destination) so it reads like theirs, and
 * never the same text this client already has on an earlier order. A
 * one-line input only ever got two or three words back.
 *
 * Deterministic per order (the same order always gets the same suggestion,
 * so a page reload doesn't reshuffle it); the wording varies by order.
 * zlib.crc32 matches PHP's crc32(), so both stacks suggest the same text.
 */

const OPENINGS = [
  'Our order of {goods} arrived in {destination} exactly as agreed.',
  'We are very pleased with the {goods} NexaCrest supplied to us in {destination}.',
  'The {goods} shipped to {destination} met our quality expectations in every detail.',
  'This was a smooth order from start to finish — {goods}, delivered to {destination} on schedule.',
];

const MIDDLES = [
  'The finish and dimensions matched what we approved, and the packing kept every piece safe in transit.',
  'The shipping documents were accurate and on time, which made clearance at our end straightforward.',
  'Communication was clear at every stage, from the quotation to the final documents.',
  'The team kept us updated on production and shipment without us ever having to chase.',
];

const CLOSINGS = [
  'We look forward to placing our next order with NexaCrest.',
  'We would happily recommend NexaCrest to other importers.',
  'Thank you to the whole team — we will be back for our next shipment.',
  'A reliable partner we are glad to keep working with.',
];

function goods(products) {
  const active = (products || []).filter((p) => Number(p.is_active ?? 1) === 1);
  if (!active.length) {
    return 'goods';
  }
  const first = active[0];
  const name = String(first.description || '').trim() || 'goods';
  if (active.length > 1) {
    return `${name} and the rest of the order`;
  }
  const quantity = parseFloat(first.quantity);
  if (!Number(first.quantity_is_tbc) && quantity > 0) {
    const qty = quantity.toLocaleString('en-US', { maximumFractionDigits: 3 });
    return `${`${qty} ${first.unit || ''}`.trim()} of ${name}`;
  }
  return name;
}

function destination(order) {
  for (const key of ['port_of_discharge_city', 'port_of_discharge_name', 'port_of_discharge_text', 'country_of_destination', 'consignee_country']) {
    const value = String(order[key] || '').trim();
    if (value) {
      return value;
    }
  }
  return 'our port';
}

/**
 * @param {string[]} previousComments what this client already has on earlier orders — never repeated
 * @param {string[]} earlierOrderReferences the client's earlier orders — their wording is never reused
 */
function build(order, products, previousComments = [], earlierOrderReferences = []) {
  const facts = { '{goods}': goods(products), '{destination}': destination(order) };
  const seed = crc32(String(order.order_reference ?? order.id ?? ''));
  const combinations = OPENINGS.length * MIDDLES.length * CLOSINGS.length;
  const taken = new Set(previousComments.map((c) => String(c).trim()));
  let usedCombinations = new Set(earlierOrderReferences.map((reference) => crc32(String(reference)) % combinations));
  if (usedCombinations.size >= combinations) {
    usedCombinations = new Set();
  }

  let text = '';
  for (let attempt = 0; attempt < combinations; attempt++) {
    const n = (seed + attempt) % combinations;
    if (usedCombinations.has(n)) {
      continue;
    }
    text = `${OPENINGS[n % 4]} ${MIDDLES[Math.floor(n / 4) % 4]} ${CLOSINGS[Math.floor(n / 16) % 4]}`
      .replace(/\{goods\}|\{destination\}/g, (token) => facts[token]);
    if (!taken.has(text)) {
      break;
    }
  }
  return text;
}

async function forOrder(orderId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    return '';
  }
  const products = await orderProductRepository.forOrder(orderId);
  const previous = (await db.query(
    'SELECT comments FROM order_feedback WHERE client_id = :client_id AND order_id <> :order_id AND comments IS NOT NULL',
    { client_id: order.client_id, order_id: orderId }
  )).map((r) => r.comments);
  const earlier = (await db.query(
    'SELECT order_reference FROM orders WHERE client_id = :client_id AND id < :order_id',
    { client_id: order.client_id, order_id: orderId }
  )).map((r) => r.order_reference);
  return build(order, products, previous, earlier);
}

module.exports = { forOrder, build };
