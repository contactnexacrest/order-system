'use strict';

// Owner decision D-257 Phase 1 (5 Nov 2026): what a generated document takes from
// the product categories of the order's goods lines — the packing words, the
// quality documents to list, and which T&C clause sets print.
//
// A Natural Stone order (every goods line in the system category Natural Stone —
// whose own settings never change a document — or in a category whose clause set
// is natural_stone or empty, or no goods lines at all) gets exactly today's words: "Wooden Crates", "Crate", "Net Weight (stone
// only)", "Material / Stone Type", the stone tolerance, no extra quality documents
// and the stone clause set. That is proved by stoneDocumentsUnchanged.test.js
// against captures made before Phase 1. PHP twin: CategoryDocumentProfile.php.

const db = require('../config/db');
const productCategoryRepository = require('../repositories/productCategoryRepository');

const STONE_SET = 'natural_stone';
const STONE_PACKING = 'Wooden Crates';
const STONE_TOLERANCE = '±2 mm on L and W · ±0.5 mm on thickness';
const GENERAL_TOLERANCE = 'As per the approved drawing / specification';

function stone() {
  return {
    stone_only: true,
    clause_sets: [STONE_SET],
    packing_label: STONE_PACKING,
    package_noun: 'Crate',
    package_nouns: 'Crates',
    package_lower: 'crate',
    package_upper: 'CRATE',
    package_count_label: 'No. of Packages / Crates',
    net_weight_label: 'Net Weight (stone only)',
    material_label: 'Material / Stone Type',
    tolerance_default: STONE_TOLERANCE,
    quality_documents: [],
  };
}

function fromCategories(categories) {
  const profile = stone();
  if (!categories || categories.length === 0) return profile;
  const sorted = [...categories].sort((a, b) => Number(a.id) - Number(b.id));
  const sets = new Set();
  const packings = new Set();
  const quality = new Map();
  let allStone = true;
  for (const c of sorted) {
    if (Number(c.is_system) === 1) {
      // Natural Stone (the system category) always prints today's documents,
      // whatever its settings say: a change there would alter every current
      // PDF, which needs the owner's double confirmation (CLAUDE.md).
      sets.add(STONE_SET);
      packings.add(STONE_PACKING);
      continue;
    }
    const set = String(c.tc_clause_set ?? '').trim() || STONE_SET;
    sets.add(set);
    const isStone = set === STONE_SET;
    allStone = allStone && isStone;
    const packing = String(c.packing_type ?? '').trim();
    packings.add(packing !== '' ? packing : (isStone ? STONE_PACKING : 'Packages'));
    for (const doc of productCategoryRepository.qualityDocuments(c)) {
      const key = doc.toLowerCase();
      if (!quality.has(key)) quality.set(key, { name: doc, category: String(c.name) });
    }
  }
  profile.clause_sets = [...sets].sort();
  profile.quality_documents = [...quality.values()];
  if (allStone) return profile; // still today's words, even if a stone category names its own packing
  const packingLabels = [...packings];
  return {
    ...profile,
    stone_only: false,
    packing_label: packingLabels.length === 1 ? packingLabels[0] : 'Packages',
    package_noun: 'Package',
    package_nouns: 'Packages',
    package_lower: 'package',
    package_upper: 'PACKAGE',
    package_count_label: 'No. of Packages',
    net_weight_label: 'Net Weight (goods only)',
    material_label: 'Material / Product Type',
    tolerance_default: GENERAL_TOLERANCE,
  };
}

async function forOrder(orderId) {
  const rows = await db.query(
    `SELECT DISTINCT COALESCE(op.category_id, (SELECT pc.id FROM product_categories pc WHERE pc.is_system = 1 ORDER BY pc.id LIMIT 1)) AS cid
       FROM order_products op
      WHERE op.order_id = :o AND op.line_type = 'goods' AND op.is_active = 1`,
    { o: orderId }
  );
  const categories = [];
  for (const r of rows) {
    if (!r.cid) continue;
    const c = await require('./orderSnapshotService').category(orderId, Number(r.cid)); // D-262: frozen with the order once its PI is approved
    if (c) categories.push(c);
  }
  return fromCategories(categories);
}

module.exports = { STONE_SET, STONE_PACKING, STONE_TOLERANCE, GENERAL_TOLERANCE, stone, fromCategories, forOrder };
