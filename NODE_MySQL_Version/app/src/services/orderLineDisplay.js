'use strict';

/**
 * Owner-approved layout of the order page's Products table (mockup 2, 18 Nov 2026):
 * how a line's figures are SHOWN in that table. Display only — nothing stored or
 * calculated changes, and no document uses these.
 *
 *   quantityText   "25" for PCS / SET / LOT … (piece-type units), CBM 3 decimals, SQM / SQFT 2,
 *                  any other unit as stored
 *   cbmEachText    "0.1274 CBM each" for a line with length × width × height
 *   pricedAtShort  D-374's record line, shortened: "₹91.61 / USD · 18 Nov 2026"
 *   chipText       an option chip; a bare "None" gets its specification's name ("Edge work: None")
 *
 * Port of src/Services/OrderLineDisplay.php.
 */
const lineUnits = require('./lineUnits');
const lineTable = require('./lineTable');

/** Units counted in whole pieces besides the PCS kind (PCS, PC, NOS …). */
const PIECE_UNITS = ['SET', 'SETS', 'LOT', 'LOTS', 'PAIR', 'PAIRS', 'BOX', 'BOXES', 'UNIT', 'UNITS', 'PKG', 'PKGS', 'PACK', 'PACKS', 'BUNDLE', 'BUNDLES', 'CRATE', 'CRATES'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const fmt = (v, dp) => Number(v).toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp });

function isPieceUnit(unit) {
  return lineUnits.canonical(unit) === 'PCS' || PIECE_UNITS.includes(String(unit ?? '').trim().toUpperCase());
}

function quantityText(quantity, unit) {
  if (quantity === null || quantity === undefined || quantity === '' || !Number.isFinite(Number(quantity))) return String(quantity ?? '');
  const q = Number(quantity);
  if (isPieceUnit(unit)) {
    // A part piece (rare) keeps its decimals, trimmed: 2.5 SET stays "2.5".
    return Math.abs(q - Math.round(q)) < 1e-9 ? fmt(q, 0) : fmt(q, 3).replace(/0+$/, '').replace(/\.$/, '');
  }
  switch (lineUnits.canonical(unit)) {
    case 'CBM': return fmt(q, 3);
    case 'M2':
    case 'SQFT': return fmt(q, 2);
    default: return String(quantity);
  }
}

/** "0.1274 CBM each" (L × W × H / 1,000,000, 4 decimals), or '' without all three sizes. */
function cbmEachText(line) {
  const cbm = lineTable.perPiece('CBM', lineUnits.size(line.piece_length_cm), lineUnits.size(line.piece_width_cm), lineUnits.size(line.piece_thickness_cm));
  return cbm !== null ? `${fmt(cbm, 4)} CBM each` : '';
}

/** D-374 record line, short: "₹91.61 / USD · 18 Nov 2026", "Rate not recorded", or '' for a line without a price. */
function pricedAtShort(line) {
  if (line.unit_price === null || line.unit_price === undefined) return '';
  if (line.priced_fx_rate === null || line.priced_fx_rate === undefined || !line.priced_at) return 'Rate not recorded';
  const raw = line.priced_at instanceof Date ? line.priced_at.toISOString() : String(line.priced_at);
  const [y, m, d] = raw.slice(0, 10).split('-').map(Number);
  return `₹${fmt(line.priced_fx_rate, 2)} / USD · ${d} ${MONTHS[m - 1]} ${y}`;
}

function chipText(chip) {
  const spec = String(chip.spec || '').trim();
  return !chip.gone && spec !== '' && String(chip.name).trim().toLowerCase() === 'none' ? `${spec}: ${chip.name}` : chip.name;
}

module.exports = { PIECE_UNITS, isPieceUnit, quantityText, cbmEachText, pricedAtShort, chipText };
