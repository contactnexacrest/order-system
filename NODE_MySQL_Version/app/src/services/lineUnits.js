'use strict';

/**
 * Owner decision D-270 (point 25, 11 Nov 2026): an order line may be priced in
 * any unit of its category (stone: CBM, m², sq ft, PCS, Ton, RMT). To check the
 * line against the product's prices — which are per the product's reference
 * unit (per CBM for 50 mm and above) — the line price is converted with the
 * piece size Length × Width × Thickness (cm):
 *
 *   CBM           nothing extra             same unit
 *   m² / sq ft    Thickness                 1 m² × thickness (1 m² = 10.7639 sq ft)
 *   PCS           Length × Width × Thickness one piece = L × W × T
 *   RMT           Width × Thickness         1 m × W × T
 *   Ton (and kg)  nothing extra             weight per CBM (category, product override)
 *   Set / Lot …   —                         cannot be converted → MD approval
 *
 * m² and sq ft convert into each other without a thickness. A line in the
 * reference unit itself needs nothing. Pure functions only (no database).
 *
 * PHP twin: src/Services/LineUnits.php.
 */

const SQFT_PER_M2 = 10.7639;
/** Point 17: the totals row counts containers of 8 CBM each. */
const CONTAINER_CBM = 8.0;
/** Natural stone's default weight per CBM (tonnes), set on the category. */
const STONE_T_PER_CBM = 2.7;

const ALIASES = {
  CBM: ['CBM', 'M3', 'M³', 'CUM', 'CU M', 'CUBIC METRE', 'CUBIC METER'],
  M2: ['M2', 'M²', 'SQM', 'SQ M', 'SQ. M', 'SQMT', 'SQUARE METRE', 'SQUARE METER'],
  SQFT: ['SQFT', 'SQ FT', 'SQ. FT', 'SFT', 'FT2', 'FT²', 'SQUARE FEET', 'SQUARE FOOT'],
  PCS: ['PCS', 'PC', 'PIECE', 'PIECES', 'NOS', 'NO'],
  RMT: ['RMT', 'RM', 'LM', 'RUNNING METRE', 'RUNNING METER'],
  TON: ['TON', 'TONS', 'TONNE', 'TONNES', 'MT', 'T'],
  KG: ['KG', 'KGS'],
};
const LABELS = { CBM: 'CBM', M2: 'm²', SQFT: 'sq ft', PCS: 'piece', RMT: 'running metre', TON: 'Ton', KG: 'kg' };

/** The size fields of a line, in the order they are asked for. */
const SIZE_FIELDS = { piece_length_cm: 'length', piece_width_cm: 'width', piece_thickness_cm: 'thickness' };

/** The unit's kind for conversion ("CBM", "M2", "SQFT", "PCS", "RMT", "TON", "KG"), or null when it cannot be converted. */
function canonical(unit) {
  const u = String(unit ?? '').trim().toUpperCase();
  if (u === '') return null;
  for (const [canon, names] of Object.entries(ALIASES)) {
    if (names.includes(u)) return canon;
  }
  return null;
}

/** "piece", "CBM" … for messages; the unit as written when it has no kind. */
function label(unit) {
  const c = canonical(unit);
  return c !== null ? LABELS[c] : String(unit ?? '').trim();
}

/** CBM in one unit of this kind; records what is missing. */
function volume(canon, l, w, t, tPerCbm, missing) {
  const need = (v, f) => {
    if (v === null || v === undefined || !(v > 0)) { missing.push(f); return false; }
    return true;
  };
  switch (canon) {
    case 'CBM': return 1.0;
    case 'M2': return need(t, 'piece_thickness_cm') ? t / 100 : null;
    case 'SQFT': return need(t, 'piece_thickness_cm') ? t / 100 / SQFT_PER_M2 : null;
    case 'PCS': {
      let ok = need(l, 'piece_length_cm');
      ok = need(w, 'piece_width_cm') && ok;
      ok = need(t, 'piece_thickness_cm') && ok;
      return ok ? (l / 100) * (w / 100) * (t / 100) : null;
    }
    case 'RMT': {
      let ok = need(w, 'piece_width_cm');
      ok = need(t, 'piece_thickness_cm') && ok;
      return ok ? (w / 100) * (t / 100) : null;
    }
    case 'TON': return need(tPerCbm, 'weight') ? 1 / tPerCbm : null;
    case 'KG': return need(tPerCbm, 'weight') ? 0.001 / tPerCbm : null;
    default: return null;
  }
}

/**
 * How many reference units one line unit holds — the line price divided by it is
 * the price per reference unit. { factor, missing: [size fields or "weight"], unconvertible }.
 */
function factor(lineUnit, refUnit, lCm, wCm, tCm, tPerCbm) {
  const out = { factor: null, missing: [], unconvertible: false };
  const lineRaw = String(lineUnit ?? '').trim().toUpperCase();
  let refRaw = String(refUnit ?? '').trim().toUpperCase();
  // A product without a reference unit carries the label "unit" (productSpecs): no unit, compared as it is.
  if (refRaw === 'UNIT') refRaw = '';
  const lc = canonical(lineUnit);
  const rc = canonical(refUnit);
  // No unit on the line or the product, or the same unit: compared as it is (as before point 25).
  if (lineRaw === '' || refRaw === '' || lineRaw === refRaw || (lc !== null && lc === rc)) {
    out.factor = 1.0;
    return out;
  }
  if (lc === null || rc === null) {
    out.unconvertible = true;
    return out;
  }
  const area = { M2: 1.0, SQFT: 1 / SQFT_PER_M2 };
  if (area[lc] !== undefined && area[rc] !== undefined) {
    out.factor = area[lc] / area[rc];
    return out;
  }
  const missing = [];
  const line = volume(lc, lCm, wCm, tCm, tPerCbm, missing);
  const ref = volume(rc, lCm, wCm, tCm, tPerCbm, missing);
  out.missing = [...new Set(missing)];
  if (out.missing.length || line === null || ref === null || ref <= 0) return out;
  out.factor = line / ref;
  return out;
}

/** CBM in one unit of a line (for the totals row), or null when its size is not known. */
function cbmPerUnit(unit, lCm, wCm, tCm, tPerCbm) {
  const c = canonical(unit);
  if (c === null) return null;
  const missing = [];
  const v = volume(c, lCm, wCm, tCm, tPerCbm, missing);
  return missing.length ? null : v;
}

/** Point 17: the containers (8 CBM each) for a total CBM. */
function containers(cbm) {
  return cbm > 0 ? Math.ceil(Math.round((cbm / CONTAINER_CBM) * 1e6) / 1e6) : 0;
}

/** 180 → "180", 2.50 → "2.5". */
function num(v) {
  return String(Math.round(Number(v) * 100) / 100);
}

/** The message beside a missing size field, e.g. "Enter the length (cm) — needed to compare a price per piece …". */
function missingMessage(field, lineUnit, refUnit) {
  if (field === 'weight') {
    return `Set the weight per CBM on the product or its category (Products) — needed to compare a price per ${label(lineUnit)}.`;
  }
  const what = SIZE_FIELDS[field] || field;
  return `Enter the ${what} (cm) — needed to compare a price per ${label(lineUnit)} with the product's price per ${label(refUnit)}.`;
}

/** The Dimensions text the three sizes fill in: "180 × 80 × 13 cm" (only the sizes given). */
function dimensionsText(l, w, t) {
  const parts = [l, w, t].filter((v) => v !== null && v !== undefined && v > 0).map(num);
  return parts.length ? `${parts.join(' × ')} cm` : '';
}

/**
 * Reads the three size fields. Up to 2 decimals, more than 0, at most 10000 cm.
 * Returns [{ piece_length_cm, piece_width_cm, piece_thickness_cm } (strings or null), error].
 */
function readSize(values) {
  const out = {};
  for (const [f, what] of Object.entries(SIZE_FIELDS)) {
    const raw = String((values || {})[f] ?? '').trim();
    if (raw === '') { out[f] = null; continue; }
    if (!/^\d{1,5}(\.\d{1,2})?$/.test(raw) || Number(raw) <= 0 || Number(raw) > 10000) {
      return [{}, `${what.charAt(0).toUpperCase()}${what.slice(1)} (cm) must be a number above 0, up to 10000, with at most 2 decimals (e.g. 180 or 1.5).`];
    }
    out[f] = Number(raw).toFixed(2);
  }
  return [out, null];
}

/** A size value from a line or form (null when empty). */
function size(v) {
  return v === null || v === undefined || v === '' ? null : Number(v);
}

module.exports = {
  SQFT_PER_M2, CONTAINER_CBM, STONE_T_PER_CBM, SIZE_FIELDS,
  canonical, label, factor, cbmPerUnit, containers, missingMessage, dimensionsText, num, readSize, size,
};
