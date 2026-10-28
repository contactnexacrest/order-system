'use strict';

/**
 * QA Round 5, R5-25: a document's name in a message — its type and
 * reference ("QT SC/QT/2026/…"). Some internal documents (COOPREP) have no
 * reference of their own, which printed "COOPREP null" (Node) or
 * "COOPREP  " (PHP); those are named by their order instead ("COOPREP for
 * order SC/ORD/…"). Mirrors DocumentLabel.php on the PHP stack.
 */
async function documentLabel(typeCode, reference, orderId) {
  if (reference !== null && reference !== undefined && String(reference).trim() !== '') {
    return `${typeCode} ${reference}`;
  }
  // Required here, not at the top, to keep this helper free of a load-order cycle.
  const orderRepository = require('../repositories/orderRepository');
  const order = await orderRepository.find(orderId);
  return order ? `${typeCode} for order ${order.order_reference}` : typeCode;
}

module.exports = { documentLabel };
