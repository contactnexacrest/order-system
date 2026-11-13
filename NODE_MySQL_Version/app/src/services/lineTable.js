'use strict';

/**
 * Owner decision D-339 (12 Nov 2026, owner confirmed twice — "confirm 1" and
 * "confirm 2 with option a"; mockups approved): the detailed product table of
 * the Quotation, Proforma Invoice, Order Confirmation, Commercial Invoice, Buyer
 * PO and Packing List, and the "No. of pieces" of an order line.
 *
 *   # | Material / Colour | Surface / Finish | Dimensions (cm) L W H | <Unit> per piece
 *     | Qty No. of pcs | <Unit> all pcs | Price (<CCY>) per <Unit> | Price per piece | Total
 *
 * Measures of one piece (sizes in cm):
 *   CBM   L × W × H / 1,000,000, 4 decimals
 *   SQM   L × W / 10,000, 4 decimals kept, 2 shown
 *   SQFT  SQM × 10.7639, 2 decimals
 *   PCS   one piece (no measure)
 * Measure of all pieces = measure per piece × pieces (CBM 3 decimals, SQM / SQFT 2).
 * Price per piece = unit price × measure per piece (2 decimals). The line total is
 * always the line's own amount (quantity × unit price, as today) — never worked out
 * again from the rounded figures shown.
 *
 * An order keeps the layout it had: orders.line_table_layout 'classic' (every order
 * that existed before migration 2026-11-12g — its documents print exactly as before)
 * or 'detailed' (new orders). Pure functions only (no database).
 *
 * PHP twin: src/Services/LineTable.php (same wording, same rounding).
 */

const lineUnits = require('./lineUnits');
const annexureChoice = require('./annexureChoice');
const moneyFormat = require('../helpers/moneyFormat');

const LAYOUT_CLASSIC = 'classic';
const LAYOUT_DETAILED = 'detailed';

/** The documents that print the detailed table on a 'detailed' order. */
const DOCUMENTS = ['QT', 'PI', 'OC', 'CI', 'BUYERPO', 'PL'];

/** lineUnits kind => the unit code the documents print. */
const DOC_UNITS = { CBM: 'CBM', M2: 'SQM', SQFT: 'SQFT', PCS: 'PCS' };
/** Decimals of the measure of all pieces (the line quantity) per document unit. */
const ALL_DECIMALS = { CBM: 3, SQM: 2, SQFT: 2, PCS: 0 };
/** Decimals shown for the measure of one piece. */
const PIECE_DECIMALS = { CBM: 4, SQM: 2, SQFT: 2 };

const DASH = '—';

/** PHP round($x, $d): half away from zero, blind to binary noise. */
function roundTo(x, d) {
  return require('../helpers/money').phpRound(x, d); // HNT-L01: exactly PHP round()
}

/** PHP number_format($v, $d) (en-US grouping). */
function fmt(v, d) {
  return roundTo(v, d).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

/** D-343: money in Indian grouping on an INR order (1,75,000.00), as before (fmt) in every other currency. */
function money(v, currency) {
  return moneyFormat.usesIndianGrouping(currency) ? moneyFormat.indian(v, 2) : fmt(v, 2);
}

/** PHP number_format($v, $d, '.', ''). */
function plain(v, d) {
  return roundTo(v, d).toFixed(d);
}

/** "CBM", "SQM", "SQFT", "PCS", or null for any other unit (Ton, RMT, Set, Lot …). */
function docUnit(unit) {
  const c = lineUnits.canonical(unit);
  return c !== null && DOC_UNITS[c] ? DOC_UNITS[c] : null;
}

/** A unit worked out from the piece size (CBM, SQM, SQFT). */
function isMeasureUnit(unit) {
  const u = docUnit(unit);
  return u !== null && u !== 'PCS';
}

function ok(v) {
  return v !== null && v !== undefined && Number(v) > 0;
}

/** The measure of one piece in the line's unit (rounded as it is used), or null when it cannot be worked out. */
function perPiece(unit, l, w, h) {
  const u = docUnit(unit);
  if (u === null || u === 'PCS' || !ok(l) || !ok(w)) return null;
  if (u === 'CBM') return ok(h) ? roundTo(Number(l) * Number(w) * Number(h) / 1000000, 4) : null;
  const sqm = roundTo(Number(l) * Number(w) / 10000, 4);
  return u === 'SQM' ? sqm : roundTo(sqm * lineUnits.SQFT_PER_M2, 2);
}

/** Decimals of the line quantity in this unit (CBM 3, SQM / SQFT 2, PCS 0); null for other units. */
function quantityDecimals(unit) {
  const u = docUnit(unit);
  return u !== null ? ALL_DECIMALS[u] : null;
}

/** True when the line has the size its unit is worked out from — "No. of pieces" is then required. */
function needsPieces(unit, l, w, h) {
  return perPiece(unit, l, w, h) !== null;
}

/**
 * The quantity worked out from the pieces: measure per piece × pieces (CBM 3 decimals,
 * SQM / SQFT 2), or the pieces themselves for PCS. Null when it cannot be worked out.
 */
function quantityFromPieces(unit, l, w, h, pieces) {
  if (pieces === null || pieces === undefined || pieces <= 0) return null;
  const u = docUnit(unit);
  if (u === 'PCS') return plain(pieces, 3);
  const per = perPiece(unit, l, w, h);
  if (per === null) return null;
  return plain(roundTo(roundTo(per * pieces, 6), ALL_DECIMALS[u]), 3);
}

/** Price of one piece: unit price × measure per piece, 2 decimals. */
function pricePerPiece(unitPrice, piece) {
  return unitPrice === null || unitPrice === undefined || piece === null || piece === undefined ? null : roundTo(roundTo(unitPrice * piece, 6), 2);
}

/** "No. of pieces" from a form: empty, or a whole number from 1 to 9,999,999. @returns {[number|null, string|null]} */
function readPieces(raw) {
  const v = String(raw ?? '').trim();
  if (v === '') return [null, null];
  if (!/^\d{1,7}$/.test(v) || Number(v) < 1) return [null, 'No. of pieces must be a whole number from 1 to 9999999 (e.g. 25).'];
  return [Number(v), null];
}

/** The size note from a form (at most 200 characters), or null. @returns {[string|null, string|null]} */
function readSizeNote(raw) {
  const v = String(raw ?? '').trim();
  if (v === '') return [null, null];
  if ([...v].length > 200) return [null, 'Size note: at most 200 characters.'];
  return [v, null];
}

/**
 * The quantity a line is saved with. With the size its unit is worked out from
 * (CBM / SQM / SQFT with Length and Width, and Thickness for CBM), "No. of pieces"
 * is required and the quantity is worked out from it — whatever the browser sent.
 * PCS with pieces: the quantity is the pieces. Otherwise the typed quantity stands.
 *
 * @returns {[string|null, string|null]} [quantity to save (null = keep the typed one), error]
 */
function serverQuantity(unit, l, w, h, pieces, perAnnexure, quantityIsTbc) {
  if (perAnnexure) return [null, null];
  if (needsPieces(unit, l, w, h)) {
    if (pieces === null || pieces === undefined) return [null, 'enter the No. of pieces — with the size given, the quantity is worked out from the pieces.'];
    return [quantityFromPieces(unit, l, w, h, pieces), null];
  }
  if (docUnit(unit) === 'PCS' && pieces !== null && pieces !== undefined && !quantityIsTbc) {
    return [quantityFromPieces(unit, l, w, h, pieces), null];
  }
  return [null, null];
}

/** 0.1872 → "0.1872" (CBM 4 decimals), 0.36 → "0.36" (SQM 2). */
function formatPerPiece(v, u) {
  return fmt(v, PIECE_DECIMALS[u] ?? 2);
}

/** A line quantity in its unit: "4.680" (CBM), "180.00" (SQM), "20" (PCS). */
function formatQuantity(v, u) {
  return fmt(v, ALL_DECIMALS[u] ?? 3);
}

/**
 * The summary under the order line's fields: "This line: 0.1872 CBM per piece · 25 pcs
 * = 4.680 CBM · USD 365.04 per piece · total USD 9,126.00". Null without a worked-out quantity.
 */
function summary(unit, l, w, h, pieces, unitPrice, currency) {
  const u = docUnit(unit);
  if (u === null || pieces === null || pieces === undefined) return null;
  if (u === 'PCS') {
    let text = `This line: ${fmt(pieces, 0)} pcs`;
    if (unitPrice !== null && unitPrice !== undefined) {
      text += ` · ${currency} ${fmt(unitPrice, 2)} per piece · total ${currency} ${fmt(roundTo(pieces * unitPrice, 2), 2)}`;
    }
    return text;
  }
  const per = perPiece(unit, l, w, h);
  const qty = quantityFromPieces(unit, l, w, h, pieces);
  if (per === null || qty === null) return null;
  let text = `This line: ${formatPerPiece(per, u)} ${u} per piece · ${fmt(pieces, 0)} pcs = ${formatQuantity(Number(qty), u)} ${u}`;
  if (unitPrice !== null && unitPrice !== undefined) {
    text += ` · ${currency} ${fmt(pricePerPiece(unitPrice, per), 2)} per piece · total ${currency} ${fmt(roundTo(Number(qty) * unitPrice, 2), 2)}`;
  }
  return text;
}

function size(v) {
  return v === null || v === undefined || v === '' || Number(v) <= 0 ? null : Number(v);
}

/**
 * Owner decision D-343: the total CBM of the goods lines, for the "Weight & Volume" box of a detailed order —
 *   a line with a piece size (L, W, H) and pieces: pieces × L × W × H / 1,000,000 (per piece rounded to 4 decimals, as the table);
 *   a CBM line without a size: its quantity;
 *   a SQM / SQFT line with a thickness: its area × the thickness;
 * null when there is no goods line or any goods line cannot be turned into CBM (SQM / SQFT without a thickness,
 * PCS without a size, any other unit, a quantity still to be confirmed). Services and charges are not counted.
 */
function totalCbm(lines) {
  let total = 0;
  let any = false;
  for (const line of lines) {
    if ((line.line_type || 'goods') !== 'goods') continue;
    any = true;
    const l = size(line.piece_length_cm);
    const w = size(line.piece_width_cm);
    const h = size(line.piece_thickness_cm);
    const pieces = line.pieces_count !== null && line.pieces_count !== undefined && line.pieces_count !== '' ? parseInt(line.pieces_count, 10) : 0;
    if (l !== null && w !== null && h !== null && pieces > 0) {
      total += Number(perPiece('CBM', l, w, h)) * pieces;
      continue;
    }
    const tbc = !!Number(line.quantity_is_tbc || 0) || line.quantity === null || line.quantity === undefined || line.quantity === '';
    const u = docUnit(line.unit);
    if (tbc || u === null || u === 'PCS') return null;
    const qty = Number(line.quantity);
    if (u === 'CBM') {
      total += qty;
    } else if (h !== null) {
      const sqm = u === 'SQM' ? qty : qty / lineUnits.SQFT_PER_M2;
      total += sqm * h / 100;
    } else {
      return null;
    }
  }
  return any ? roundTo(roundTo(total, 6), 3) : null;
}

/**
 * The two header rows. 'top' and 'bottom' list the cells after "#"; a top cell
 * may span ('span') columns, and then the bottom row holds one cell per column.
 */
function heads(variant, unit, currency, withPrices) {
  const measure = unit ?? 'Measure';
  const per = unit !== null ? `per ${unit}` : 'per unit';
  const price = `Price (${currency})`;
  let top;
  let bottom;
  let widths;
  if (variant === 'full') {
    top = [['Material', 1], ['Surface', 1], ['Dimensions (cm)', 3], [measure, 1], ['Qty', 1], [measure, 1]];
    bottom = ['Colour', 'Finish', 'L', 'W', 'H', 'per piece', 'No. of pcs', 'all pcs'];
    // Column widths (%) as the approved mockups; mixed units leave room for "0.1872 CBM".
    widths = withPrices
      ? (unit !== null ? [3, 13, 8, 5, 5, 5, 8, 7, 8, 12, 12, 14] : [3, 12, 8, 5, 5, 5, 10, 6, 10, 12, 10, 14])
      : (unit !== null ? [4, 18, 10, 7, 7, 7, 11, 10, 12, 14] : [4, 17, 9, 6, 6, 6, 14, 9, 15, 14]);
    if (withPrices) {
      top = top.concat([[price, 1], [price, 1], ['Total Price', 1]]);
      bottom = bottom.concat([per, 'per piece', `(${currency})`]);
    } else {
      top.push(['Crates /', 1]);
      bottom.push('Packages');
    }
  } else {
    top = [['Material', 1], ['Surface', 1], ['Thickness', 1], ['Qty', 1]];
    bottom = ['Colour', 'Finish', '(cm)', unit ?? 'in its unit'];
    widths = withPrices ? [4, 27, 13, 12, 12, 15, 17] : [5, 35, 15, 15, 15, 15];
    if (withPrices) {
      top = top.concat([[price, 1], ['Total Price', 1]]);
      bottom = bottom.concat([per, `(${currency})`]);
    } else {
      top.push(['Crates /', 1]);
      bottom.push('Packages');
    }
  }
  // Each top cell's width (%): the columns it spans.
  let wi = 1;
  const outTop = top.map(([text, span]) => {
    const width = widths.slice(wi, wi + span).reduce((a, b) => a + b, 0);
    wi += span;
    return { text, span, width };
  });
  return { top: outTop, bottom, widths };
}

/** Packing List: the crates whose product description is this line's, or a dash. */
function crates(crateCounts, description) {
  const n = crateCounts[String(description).trim().toLowerCase()] || 0;
  return n > 0 ? String(n) : DASH;
}

/**
 * The grey row under a line: "Qty as per Annexure A" · the size note · the Dimensions text of a
 * line without a piece size · another category's attributes · HS Code · Country of Origin.
 */
function detail(line, f, annexureLetter, hasSize) {
  const out = [];
  if (annexureLetter !== null) out.push({ label: null, text: `Qty as per Annexure ${annexureLetter}` });
  const note = String(line.size_note ?? '').trim();
  if (note !== '') out.push({ label: 'Sizes:', text: note });
  const dims = String(line.dimensions ?? '').trim();
  if (annexureLetter === null && !hasSize && dims !== '' && note === '') out.push({ label: 'Dimensions:', text: dims });
  for (const a of f.attributes || []) out.push({ label: `${a.label}:`, text: String(a.value) });
  out.push({ label: 'HS Code:', text: String(line.hs_code ?? '') });
  out.push({ label: 'Country of Origin:', text: 'India' });
  return out;
}

/**
 * The detailed table of a document.
 *
 * @param {object[]} lines      the order_products rows on the document (in order)
 * @param {object[]} formatted  the same lines as the documents print them today (documentDataAssembler)
 * @param {string|null} annexureLetter D-336: the annexure "As per annexure" lines point to on this document (null: none)
 * @param {Object<string,number>} crateCounts Packing List: crates per product description (lower case)
 * @param {Object<number,{material?:string, finish?:string}>} names per line id: Material / Colour and Surface / Finish
 */
function build(lines, formatted, currency, annexureLetter, withPrices, crateCounts = {}, names = {}) {
  const stone = [];
  const simple = [];
  lines.forEach((line, i) => {
    const f = { ...formatted[i], no: i + 1 };
    if ((line.line_type || 'goods') === 'goods' && docUnit(line.unit) !== null) stone.push([line, f]);
    else simple.push(f);
  });
  const out = {
    variant: 'none', mixed: false, unit: null, currency, with_prices: withPrices,
    heads: {}, rows: [], total: null, totals_by_unit: null, simple, colspan: 0,
  };
  if (stone.length === 0) return out;
  const units = [...new Set(stone.map(([l]) => docUnit(l.unit)))];
  const mixed = units.length > 1;
  let full = false;
  for (const [line] of stone) {
    const perAnnexure = annexureLetter !== null && Number(line.as_per_annexure || 0) === 1;
    if (!perAnnexure && size(line.piece_length_cm) !== null && size(line.piece_width_cm) !== null) full = true;
  }
  out.variant = full ? 'full' : 'short';
  out.mixed = mixed;
  out.unit = mixed ? null : units[0];
  out.heads = heads(out.variant, mixed ? null : units[0], currency, withPrices);
  out.colspan = out.heads.bottom.length + 1;

  let pcsTotal = 0;
  let pcsAny = false;
  const byUnit = {};
  let amount = 0;
  for (const [line, f] of stone) {
    const u = docUnit(line.unit);
    const suffix = (v) => (mixed && v !== DASH ? `${v} ${u}` : v);
    const perAnnexure = annexureLetter !== null && Number(line.as_per_annexure || 0) === 1;
    const l = size(line.piece_length_cm);
    const w = size(line.piece_width_cm);
    const h = size(line.piece_thickness_cm);
    let pieces = line.pieces_count !== null && line.pieces_count !== undefined && line.pieces_count !== '' ? Number(line.pieces_count) : null;
    const tbc = Number(line.quantity_is_tbc || 0) === 1 || line.quantity === null || line.quantity === undefined || line.quantity === '';
    const qty = tbc ? null : Number(line.quantity);
    const price = line.unit_price !== null && line.unit_price !== undefined && line.unit_price !== '' ? Number(line.unit_price) : null;
    const hasSize = !perAnnexure && l !== null && w !== null;
    const per = hasSize ? perPiece(line.unit, l, w, h) : null;
    if (u === 'PCS' && pieces === null && qty !== null && !perAnnexure) pieces = Math.round(qty);
    if (perAnnexure || (!hasSize && u !== 'PCS')) pieces = null;
    if (pieces !== null) {
      pcsTotal += pieces;
      pcsAny = true;
    }
    if (qty !== null) byUnit[u] = (byUnit[u] || 0) + qty;
    if (line.fob_value !== null && line.fob_value !== undefined && line.fob_value !== '') amount += Number(line.fob_value);
    const pricePiece = u === 'PCS' ? price : pricePerPiece(price, per);
    const nm = names[Number(line.id || 0)] || {};
    const row = {
      no: f.no,
      material: nm.material ?? String(line.description),
      finish: (nm.finish ?? '') !== '' ? nm.finish : (String(line.finish ?? '').trim() !== '' ? String(line.finish) : DASH),
      annexure: perAnnexure ? annexureChoice.dimensionsText(annexureLetter) : null,
      l: hasSize ? lineUnits.num(l) : DASH,
      w: hasSize ? lineUnits.num(w) : DASH,
      h: hasSize && h !== null ? lineUnits.num(h) : DASH,
      thickness: !perAnnexure && h !== null ? lineUnits.num(h) : DASH,
      per_piece: per !== null ? suffix(formatPerPiece(per, u)) : DASH,
      pcs: pieces !== null ? fmt(pieces, 0) : DASH,
      all_pcs: qty !== null ? suffix(formatQuantity(qty, u)) : 'To Be Confirmed',
      // The short table's "Qty" (every unit, PCS included).
      qty: qty !== null ? suffix(formatQuantity(qty, u)) : 'To Be Confirmed',
      // D-343: money in Indian grouping on an INR order (1,75,000.00), as before in every other currency.
      price_unit: price !== null ? money(price, currency) + (mixed ? ` /${u}` : '') : 'To Be Confirmed',
      price_piece: pricePiece !== null && !perAnnexure && (per !== null || u === 'PCS') ? money(pricePiece, currency) : DASH,
      total: String(f.amount ?? ''),
      crates: crates(crateCounts, line.description),
      detail: detail(line, f, perAnnexure ? annexureLetter : null, hasSize),
    };
    if (u === 'PCS') {
      row.per_piece = DASH;
      row.all_pcs = DASH;
    }
    out.rows.push(row);
  }
  const parts = [];
  for (const u of ['CBM', 'SQM', 'SQFT', 'PCS']) {
    if (byUnit[u] !== undefined) parts.push(`${formatQuantity(byUnit[u], u)} ${u}`);
  }
  out.total = {
    pcs: pcsAny ? fmt(pcsTotal, 0) : '',
    // One unit: its total (a PCS-only full table counts the pieces instead). Mixed units are never added up.
    measure: !mixed && byUnit[units[0]] !== undefined && (units[0] !== 'PCS' || out.variant === 'short') ? formatQuantity(byUnit[units[0]], units[0]) : '',
    amount: money(amount, currency),
  };
  out.totals_by_unit = mixed && parts.length > 0 ? `Totals by unit: ${parts.join(' · ')}` : null;
  return out;
}

module.exports = {
  LAYOUT_CLASSIC, LAYOUT_DETAILED, DOCUMENTS, DASH,
  roundTo, docUnit, isMeasureUnit, perPiece, quantityDecimals, needsPieces, quantityFromPieces, pricePerPiece,
  readPieces, readSizeNote, serverQuantity, formatPerPiece, formatQuantity, summary, build, totalCbm,
};
