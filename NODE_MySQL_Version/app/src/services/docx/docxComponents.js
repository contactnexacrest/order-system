'use strict';

/**
 * Shared `docx` (npm package) building blocks that translate _layout.njk's
 * CSS (colors, section-title bars, kv tables, colored notice boxes,
 * product tables, signature block, header, watermark) into real Word
 * formatting — the Node sibling of PHP_MySQL_Version's DocxComponents.php.
 *
 * Every per-document-type render function in docxDocumentBuilder.js is
 * built out of these pieces so the 11 document types stay visually
 * consistent with each other and with the PDF, without repeating
 * table-styling code 11 times.
 *
 * The `docx` package works differently from PHPWord: instead of mutating a
 * `Section` object with `addTable()`/`addText()` calls, you build a flat
 * array of block-level children (Paragraph / Table) and hand the whole
 * array to the Document section. So every helper below RETURNS an element
 * (or an array of elements) to be spread into that children array, rather
 * than appending to something passed in.
 *
 * Colors are the literal hex values from _layout.njk's <style> block
 * (without the leading '#' — docx convention, same as PHPWord's).
 */

const {
  Document,
  Header,
  Paragraph,
  Table,
  TableRow,
  TableCell,
  TextRun,
  ImageRun,
  WpsShapeRun,
  ShadingType,
  BorderStyle,
  WidthType,
  AlignmentType,
  VerticalAlign,
  TextWrappingType,
} = require('docx');

// --------------------------------------------------------------------
// Colors / constants
// --------------------------------------------------------------------

const NAVY = '17233A';
const NAVY_MID = '173B73';
const GRAY_LIGHT = 'F1F5F9';
const BORDER_GRAY = 'D7DEE6';
const MUTED = '5B6573';
const SPEC_BG = 'EFF3F7';
const WHITE = 'FFFFFF';
const BLACK = '000000';

const AMBER_BG = 'FFF3CD';
const AMBER_BG2 = 'FAEEDA';
const AMBER_BORDER = '8A6D1D';
const AMBER_LABEL = '633806';
const AMBER_VALUE = '412402';
const AMBER_NOTE = '854F0B';
const AMBER_TEXT2 = '5C4813';

const GREEN_BG = 'EAF3DE';
const GREEN_BORDER = '27500A';
const GREEN_VALUE = '3B6D11';

const RED_BG = 'FBEAEA';
const RED_BORDER = '8A1F1F';
const RED_TEXT = '8A1F1F';
const RED_SUB = '5A1414';

const BLUE_BG = 'E6F1FB';
const BLUE_BORDER = '1D6FA8';
const BLUE_BG2 = 'EAF1F9';
const BLUE_BORDER2 = '17233A';
const BLUE_TEXT = '185FA5';

const FONT = 'Carlito';

// --------------------------------------------------------------------
// Document-level setup
// --------------------------------------------------------------------

/**
 * Default styles block. `docx` sizes are in HALF-POINTS (PHPWord's are in
 * points) and spacing is in TWIPS (same unit PHPWord uses for spaceAfter),
 * so PHPWord's 9.5pt default font -> size: 19 here.
 */
function documentDefaultStyles() {
  return {
    default: {
      document: {
        run: { font: FONT, size: 19 },
        paragraph: { spacing: { after: 60, before: 0 } },
      },
    },
  };
}

const PAGE_A4 = {
  size: { width: 11906, height: 16838 }, // A4 in twips
  margin: { top: 720, bottom: 720, left: 850, right: 850 }, // 0.5in / 0.59in, matches PHP
};

const TABLE_MARGINS = { top: 40, bottom: 40, left: 100, right: 100 };
const FULL_WIDTH = { size: 100, type: WidthType.PERCENTAGE };

// --------------------------------------------------------------------
// Small shared helpers
// --------------------------------------------------------------------

/**
 * `docx`'s ShadingType.SOLID paints the cell using `color` as a 100%
 * foreground pattern (with `fill` as the pattern's background) — for a
 * plain flat cell color that renders as solid BLACK, not the intended
 * hex. ShadingType.CLEAR (no pattern) is what actually shows `fill` as a
 * flat background color. This is a known `docx`-package footgun — never
 * use SOLID for a flat fill.
 */
function shade(hex) {
  if (!hex) return undefined;
  return { fill: hex, type: ShadingType.CLEAR, color: 'auto' };
}

function borderSide(color, size) {
  return { style: BorderStyle.SINGLE, size, color };
}

/** Thin border on all 4 sides, matching PHPWord's borderSize=4 (eighths of a point). */
function thinBorders(color = BORDER_GRAY, size = 4) {
  return {
    top: borderSide(color, size),
    bottom: borderSide(color, size),
    left: borderSide(color, size),
    right: borderSide(color, size),
  };
}

const NO_BORDERS = {
  top: { style: BorderStyle.NONE, size: 0, color: 'auto' },
  bottom: { style: BorderStyle.NONE, size: 0, color: 'auto' },
  left: { style: BorderStyle.NONE, size: 0, color: 'auto' },
  right: { style: BorderStyle.NONE, size: 0, color: 'auto' },
};

function pctWidth(pct) {
  return { size: pct, type: WidthType.PERCENTAGE };
}

/** pt -> px at 96dpi, which is what `docx`'s ImageRun transformation.width/height expect. */
function ptToPx(pt) {
  return Math.round(pt * (96 / 72));
}

function run(text, opts = {}) {
  return new TextRun(Object.assign({ text: String(text ?? ''), font: FONT }, opts));
}

/** A paragraph made of one or more styled runs — the equivalent of PHPWord's addTextRun(). */
function rich(segments, pStyle = {}) {
  const children = segments.map(([text, style]) => run(text, style || {}));
  return new Paragraph(Object.assign({ children }, pStyle));
}

function plain(text, style = {}, pStyle = {}) {
  return new Paragraph(Object.assign({ children: [run(text, style)], spacing: { after: 60 } }, pStyle));
}

/** Vertical breathing room between blocks — PHPWord's addTextBreak(1, N). */
function spacer(afterTwips = 120) {
  return new Paragraph({ text: '', spacing: { after: afterTwips } });
}

function cell(children, opts = {}) {
  const paras = Array.isArray(children) ? children : [children];
  return new TableCell(
    Object.assign(
      {
        children: paras.map((p) => (p instanceof Paragraph ? p : plain(String(p ?? '')))),
        verticalAlign: VerticalAlign.TOP,
        borders: NO_BORDERS,
        margins: TABLE_MARGINS,
      },
      opts
    )
  );
}

/** Renders a KV-table / colorBox "value" that may be a plain string or a
 * function `(pushParagraph) => void` for rich, multi-paragraph content —
 * the JS equivalent of PHP's `string|callable` value shape. */
function renderValue(value) {
  if (typeof value === 'function') {
    const paras = [];
    value((p) => paras.push(p));
    return paras.length ? paras : [plain('')];
  }
  if (value instanceof Paragraph) return [value];
  if (Array.isArray(value)) return value;
  return [plain(String(value ?? ''))];
}

// --------------------------------------------------------------------
// Image helpers (no `sharp`/`canvas` in this environment — a tiny manual
// PNG/JPEG header parser is enough to preserve aspect ratio for the logo,
// seal and signature images).
// --------------------------------------------------------------------

function decodeDataUri(dataUri) {
  if (!dataUri || typeof dataUri !== 'string' || !dataUri.startsWith('data:')) return null;
  const comma = dataUri.indexOf(',');
  if (comma === -1) return null;
  const meta = dataUri.slice(5, comma);
  const buffer = Buffer.from(dataUri.slice(comma + 1), 'base64');
  let type = 'png';
  if (meta.includes('jpeg') || meta.includes('jpg')) type = 'jpg';
  else if (meta.includes('gif')) type = 'gif';
  else if (meta.includes('png')) type = 'png';
  return { buffer, type };
}

/** Returns {width, height} in pixels, or null if the format isn't recognized. */
function readImageSize(buffer) {
  try {
    // PNG: signature (8 bytes) + IHDR chunk length(4) + "IHDR"(4) + width(4) + height(4)
    if (buffer.length > 24 && buffer.readUInt32BE(0) === 0x89504e47 /* not exact but checked below */) {
      // fallthrough — real check below via bytes
    }
    if (
      buffer[0] === 0x89 && buffer[1] === 0x50 && buffer[2] === 0x4e && buffer[3] === 0x47 &&
      buffer.length > 24
    ) {
      const width = buffer.readUInt32BE(16);
      const height = buffer.readUInt32BE(20);
      return { width, height };
    }
    // GIF: "GIF87a"/"GIF89a" + width(2 LE) + height(2 LE)
    if (buffer[0] === 0x47 && buffer[1] === 0x49 && buffer[2] === 0x46 && buffer.length > 10) {
      const width = buffer.readUInt16LE(6);
      const height = buffer.readUInt16LE(8);
      return { width, height };
    }
    // JPEG: scan markers for SOFn (0xC0-0xC3, 0xC5-0xC7, 0xC9-0xCB, 0xCD-0xCF)
    if (buffer[0] === 0xff && buffer[1] === 0xd8) {
      let offset = 2;
      while (offset < buffer.length - 8) {
        if (buffer[offset] !== 0xff) {
          offset++;
          continue;
        }
        const marker = buffer[offset + 1];
        if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd9)) {
          offset += 2;
          continue;
        }
        const length = buffer.readUInt16BE(offset + 2);
        if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
          const height = buffer.readUInt16BE(offset + 5);
          const width = buffer.readUInt16BE(offset + 7);
          return { width, height };
        }
        offset += 2 + length;
      }
    }
  } catch (e) {
    // fall through to null
  }
  return null;
}

/** Builds an ImageRun for a data-URI image at a fixed target height (points), preserving aspect ratio. */
function imageAtHeight(dataUri, targetHeightPt, fallbackWidthPt) {
  const decoded = decodeDataUri(dataUri);
  if (!decoded) return null;
  const size = readImageSize(decoded.buffer);
  const heightPx = ptToPx(targetHeightPt);
  let widthPx = ptToPx(fallbackWidthPt ?? targetHeightPt);
  if (size && size.height > 0) {
    widthPx = Math.round(heightPx * (size.width / size.height));
  }
  return new ImageRun({
    data: decoded.buffer,
    type: decoded.type === 'jpg' ? 'jpg' : decoded.type,
    transformation: { width: widthPx, height: heightPx },
  });
}

/** Builds an ImageRun for a data-URI image constrained to a max width/height box (used by Annexure A product photos). */
function imageInBox(dataUri, maxWidthPt, maxHeightPt) {
  const decoded = decodeDataUri(dataUri);
  if (!decoded) return null;
  const size = readImageSize(decoded.buffer);
  let widthPx = ptToPx(maxWidthPt);
  let heightPx = ptToPx(maxHeightPt);
  if (size && size.width > 0 && size.height > 0) {
    const ratio = Math.min(ptToPx(maxWidthPt) / size.width, ptToPx(maxHeightPt) / size.height);
    widthPx = Math.round(size.width * ratio);
    heightPx = Math.round(size.height * ratio);
  }
  return new ImageRun({
    data: decoded.buffer,
    type: decoded.type === 'jpg' ? 'jpg' : decoded.type,
    transformation: { width: widthPx, height: heightPx },
  });
}

// --------------------------------------------------------------------
// Header: logo image + company name / doc title / incoterm chip
// --------------------------------------------------------------------

function header(context, docTitle, incotermText) {
  const logoDataUri = context?.assets?.logo_data_uri || null;
  const companyName = String(context?.company?.legal_name || '').toUpperCase();

  const titleParas = [
    new Paragraph({ alignment: AlignmentType.RIGHT, children: [run(companyName, { bold: true, size: 26, color: NAVY })] }),
    new Paragraph({
      alignment: AlignmentType.RIGHT,
      spacing: { before: 20 },
      children: [run(docTitle, { bold: true, size: 36, color: NAVY })],
    }),
  ];
  if (incotermText) {
    titleParas.push(
      new Paragraph({
        alignment: AlignmentType.RIGHT,
        spacing: { before: 20 },
        children: [run(incotermText, { bold: true, size: 20, color: MUTED })],
      })
    );
  }

  const logoImage = logoDataUri ? imageAtHeight(logoDataUri, 40, 40) : null;
  const cells = [];
  if (logoImage) {
    cells.push(cell([new Paragraph({ children: [logoImage] })], { width: pctWidth(15), verticalAlign: VerticalAlign.CENTER }));
    cells.push(cell(titleParas, { width: pctWidth(85), verticalAlign: VerticalAlign.CENTER }));
  } else {
    cells.push(cell(titleParas, { width: pctWidth(100), verticalAlign: VerticalAlign.CENTER }));
  }

  const table = new Table({ width: FULL_WIDTH, margins: TABLE_MARGINS, borders: NO_BORDERS, rows: [new TableRow({ children: cells })] });
  return [table, spacer(120)];
}

function mandatoryNote() {
  return rich(
    [
      ['Fields marked ', { italics: true, size: 18, color: MUTED }],
      ['*', { bold: true, size: 18, color: 'C0392B' }],
      [' are mandatory and must be completed before this document is issued.', { italics: true, size: 18, color: MUTED }],
    ],
    { spacing: { after: 160 } }
  );
}

/**
 * The N-column shaded meta strip (QUOTATION NO. / DATE / BUYER INQUIRY
 * REF etc.) — one row, one cell per item, each with a small gray label
 * over a bold navy value.
 */
function metaBar(items) {
  const count = Math.max(1, items.length);
  const width = 100 / count;
  const cells = items.map((item) =>
    cell(
      [plain(String(item.label).toUpperCase(), { size: 16, color: MUTED }, { spacing: { after: 20 } }), plain(String(item.value ?? ''), { bold: true, size: 20, color: NAVY })],
      { width: pctWidth(width), shading: shade(GRAY_LIGHT), borders: thinBorders(), verticalAlign: VerticalAlign.TOP }
    )
  );
  const table = new Table({ width: FULL_WIDTH, margins: TABLE_MARGINS, rows: [new TableRow({ children: cells })] });
  return [table, spacer(120)];
}

// --------------------------------------------------------------------
// Section title bar: solid navy, white bold text
// --------------------------------------------------------------------

function sectionTitle(text, bg = NAVY) {
  const table = new Table({
    width: FULL_WIDTH,
    margins: TABLE_MARGINS,
    rows: [new TableRow({ children: [cell(plain(text, { bold: true, size: 22, color: WHITE }), { width: pctWidth(100), shading: shade(bg), verticalAlign: VerticalAlign.CENTER })] })],
  });
  return [table, spacer(80)];
}

// --------------------------------------------------------------------
// Colored notice box (single-cell "div") — amber / green / red / blue
// boxes throughout every template. `content` is an array of Paragraphs
// (build them with rich()/plain(), or a function `(push) => {...}` like
// KV/table cell values).
// --------------------------------------------------------------------

function colorBox(bg, borderColor, content, borderSize = 5) {
  const paras = renderValue(content);
  const table = new Table({
    width: FULL_WIDTH,
    margins: TABLE_MARGINS,
    rows: [
      new TableRow({
        children: [cell(paras, { width: pctWidth(100), shading: shade(bg), borders: thinBorders(borderColor, borderSize), verticalAlign: VerticalAlign.TOP })],
      }),
    ],
  });
  return [table, spacer(120)];
}

// --------------------------------------------------------------------
// KV table: two-column, alternating-shade bold navy labels, thin borders.
// rows: [{label, value, labelBg, valueBg, full, bg}]
// --------------------------------------------------------------------

function kvTable(rows, solidLabel = false, labelWidthPct = 30) {
  const valueWidthPct = 100 - labelWidthPct;
  const trRows = rows.map((row, i) => {
    if (row.full) {
      return new TableRow({
        children: [cell(renderValue(row.value), { width: pctWidth(100), shading: shade(row.bg), borders: thinBorders(), verticalAlign: VerticalAlign.TOP })],
      });
    }
    const shaded = solidLabel || i % 2 === 0;
    const labelCell = cell(plain(String(row.label ?? ''), { bold: true, size: 19, color: NAVY }), {
      width: pctWidth(labelWidthPct),
      shading: shade(row.labelBg || (shaded ? GRAY_LIGHT : WHITE)),
      borders: thinBorders(),
      verticalAlign: VerticalAlign.TOP,
    });
    const valueCell = cell(renderValue(row.value).map((p) => p), {
      width: pctWidth(valueWidthPct),
      shading: shade(row.valueBg !== undefined ? row.valueBg : WHITE),
      borders: thinBorders(),
      verticalAlign: VerticalAlign.TOP,
    });
    return new TableRow({ children: [labelCell, valueCell] });
  });
  const table = new Table({ width: FULL_WIDTH, margins: TABLE_MARGINS, rows: trRows });
  return [table, spacer(120)];
}

// --------------------------------------------------------------------
// Product table: navy-mid header, alternating spec sub-rows.
// headers: string[]; widthsPct: number[] (percent, sums to 100)
// rows: [{cells: string[], align: (null|'right'|'center')[], lineColor, specText: [[text, style], ...]}]
// --------------------------------------------------------------------

function productsTable(headers, widthsPct, rows) {
  const headerRow = new TableRow({
    children: headers.map((h, i) =>
      cell(plain(h, { bold: true, size: 18, color: WHITE }), { width: pctWidth(widthsPct[i]), shading: shade(NAVY_MID), verticalAlign: VerticalAlign.CENTER })
    ),
  });

  const bodyRows = [];
  rows.forEach((row) => {
    const cells = row.cells.map((val, i) => {
      const align = row.align && ['right', 'center'].includes(row.align[i]) ? row.align[i] : undefined;
      const color = i === 0 && row.lineColor ? row.lineColor : undefined;
      const p = new Paragraph({
        alignment: align === 'right' ? AlignmentType.RIGHT : align === 'center' ? AlignmentType.CENTER : undefined,
        children: [run(String(val ?? ''), { bold: i === 0, color, size: 19 })],
      });
      return cell(p, { width: pctWidth(widthsPct[i]), borders: thinBorders(), verticalAlign: VerticalAlign.TOP });
    });
    bodyRows.push(new TableRow({ children: cells }));

    if (row.specText) {
      const specChildren = row.specText.map(([text, style]) => run(text, Object.assign({ size: 17 }, style || {})));
      bodyRows.push(
        new TableRow({
          children: [
            cell(new Paragraph({ children: specChildren }), {
              width: pctWidth(100),
              columnSpan: headers.length,
              shading: shade(SPEC_BG),
              borders: thinBorders(),
              verticalAlign: VerticalAlign.TOP,
            }),
          ],
        })
      );
    }
  });

  const table = new Table({ width: FULL_WIDTH, margins: TABLE_MARGINS, rows: [headerRow, ...bodyRows] });
  return [table, spacer(120)];
}

// --------------------------------------------------------------------
// Totals table: last (highlight) row uses navy-mid, white bold.
// rows: [{label, value, highlight}]
// --------------------------------------------------------------------

function totalsTable(rows, labelWidthPct = 55) {
  const valueWidthPct = 100 - labelWidthPct;
  const trRows = rows.map((row) => {
    const highlight = !!row.highlight;
    const labelCell = cell(plain(String(row.label ?? ''), { bold: true, size: highlight ? 21 : 19, color: highlight ? WHITE : NAVY }), {
      width: pctWidth(labelWidthPct),
      shading: shade(highlight ? NAVY : WHITE),
      borders: thinBorders(),
      verticalAlign: VerticalAlign.TOP,
    });
    const valueCell = cell(renderValue(row.value), {
      width: pctWidth(valueWidthPct),
      shading: shade(highlight ? NAVY_MID : WHITE),
      borders: thinBorders(),
      verticalAlign: VerticalAlign.TOP,
    });
    return new TableRow({ children: [labelCell, valueCell] });
  });
  const table = new Table({ width: FULL_WIDTH, margins: TABLE_MARGINS, rows: trRows });
  return [table, spacer(120)];
}

// --------------------------------------------------------------------
// Checklist table (COOPREP): dark-navy header row.
// rows: [{cells: (string|function|Paragraph)[]}]
// --------------------------------------------------------------------

function checklistTable(headers, widthsPct, rows) {
  const headerRow = new TableRow({
    children: headers.map((h, i) => cell(plain(h, { bold: true, size: 18, color: WHITE }), { width: pctWidth(widthsPct[i]), shading: shade(NAVY), verticalAlign: VerticalAlign.CENTER })),
  });
  const bodyRows = rows.map(
    (row) =>
      new TableRow({
        children: row.cells.map((val, i) => cell(renderValue(val), { width: pctWidth(widthsPct[i]), borders: thinBorders(), verticalAlign: VerticalAlign.TOP })),
      })
  );
  const table = new Table({ width: FULL_WIDTH, margins: TABLE_MARGINS, rows: [headerRow, ...bodyRows] });
  return [table, spacer(120)];
}

/** A "Step N" navy badge cell used by COOPREP's step-by-step table. */
function stepBadge(label) {
  return new Paragraph({ alignment: AlignmentType.CENTER, children: [run(label, { bold: true, size: 18, color: WHITE })] });
}

// --------------------------------------------------------------------
// The 3-column "Field / Value / Remark" finance table shared by SUPPO
// section 4 and CI section 5.
// rows: [[label, value, remark, highlight?]]
// --------------------------------------------------------------------

function threeColFinanceTable(headerText, rows, headerBg) {
  const headerRow = new TableRow({
    children: [cell(plain(headerText, { bold: true, color: WHITE }), { width: pctWidth(100), columnSpan: 3, shading: shade(headerBg) })],
  });
  const bodyRows = rows.map(([label, value, remark, highlight]) => {
    const bg = highlight ? AMBER_BG : WHITE;
    return new TableRow({
      children: [
        cell(plain(String(label ?? ''), { bold: true, color: NAVY, size: 19 }), { width: pctWidth(34), shading: shade(bg), borders: thinBorders() }),
        cell(
          new Paragraph({ alignment: AlignmentType.RIGHT, children: [run(String(value ?? ''), { bold: !!highlight, color: highlight ? RED_TEXT : BLACK, size: 19 })] }),
          { width: pctWidth(26), shading: shade(bg), borders: thinBorders() }
        ),
        cell(plain(String(remark ?? ''), { italics: true, size: 17, color: '555555' }), { width: pctWidth(40), borders: thinBorders() }),
      ],
    });
  });
  const table = new Table({ width: FULL_WIDTH, margins: TABLE_MARGINS, rows: [headerRow, ...bodyRows] });
  return [table, spacer(120)];
}

// --------------------------------------------------------------------
// Bullet list / plain paragraphs
// --------------------------------------------------------------------

function bulletList(items) {
  return items.map(
    (text) =>
      new Paragraph({
        bullet: { level: 0 },
        spacing: { after: 60 },
        children: [run(String(text ?? ''), { size: 19 })],
      })
  );
}

// --------------------------------------------------------------------
// Signature block: seal + signature images, name/designation/company,
// disclaimer text.
// --------------------------------------------------------------------

function signatureBlock(context) {
  const company = context.company || {};
  const signatory = context.signatory || {};
  const out = [spacer(200)];

  const row1 = new TableRow({
    children: [
      cell(plain(`For\n${company.legal_name || ''}`, { bold: true, color: NAVY, size: 20 }), { width: pctWidth(50) }),
      cell(plain('Authorised Signatory', { bold: true, color: MUTED, size: 20 }), { width: pctWidth(50) }),
    ],
  });

  const sealImg = signatory.seal_data_uri ? imageAtHeight(signatory.seal_data_uri, 60) : null;
  const sigImg = signatory.signature_data_uri ? imageAtHeight(signatory.signature_data_uri, 24) : null;

  const sealParas = sealImg ? [new Paragraph({ children: [sealImg] })] : [plain('')];
  const sigParas = [];
  if (sigImg) sigParas.push(new Paragraph({ children: [sigImg] }));
  sigParas.push(plain(String(signatory.name || ''), { bold: true, size: 26, color: NAVY }));
  sigParas.push(plain(String(signatory.designation || ''), { bold: true, size: 20, color: BLACK }));
  sigParas.push(plain(String(company.legal_name || ''), { size: 18, color: MUTED }));

  const row2 = new TableRow({
    children: [
      cell(sealParas, { width: pctWidth(50), verticalAlign: VerticalAlign.BOTTOM }),
      cell(sigParas, { width: pctWidth(50), verticalAlign: VerticalAlign.BOTTOM }),
    ],
  });

  out.push(new Table({ width: FULL_WIDTH, margins: TABLE_MARGINS, borders: NO_BORDERS, rows: [row1, row2] }));

  const showDisclaimer = (company.show_generated_document_disclaimer ?? true) && (sigImg || sealImg);
  if (showDisclaimer) {
    const text =
      company.generated_document_disclaimer_text ||
      "This is a system-generated document. The signature and company seal shown are NexaCrest's authorised electronic signature and digital company seal, applied automatically by the order management system under internal document-authorisation controls.";
    out.push(plain(text, { italics: true, size: 15, color: '888888' }, { spacing: { before: 120 } }));
  }
  return out;
}

// --------------------------------------------------------------------
// Terms & conditions section (numbered clauses)
// --------------------------------------------------------------------

function termsSection(context, number, title) {
  const terms = context.terms || [];
  if (!terms.length) return [];
  const out = [...sectionTitle(`${number}. ${title}`)];
  if (context.order && context.order.include_annexure_a) {
    const docTitle = String(context.doc_title || 'document').toLowerCase();
    out.push(
      ...colorBox(
        RED_BG,
        RED_BORDER,
        [
          plain(
            `⚠ Annexure A — Product Technical Specifications is attached and forms an integral part of this ${docTitle}. Refer Annexure A for product images, technical drawings, and component dimensions.`,
            { bold: true, size: 20, color: RED_TEXT }
          ),
        ]
      )
    );
  }
  out.push(...bulletList(terms));
  out.push(spacer(80));
  return out;
}

// --------------------------------------------------------------------
// Watermark: a rotated, semi-transparent diagonal text box anchored to
// the page, placed in the section header so it repeats on every page.
//
// PHP's approach rasterizes the text via GD into a PNG because PHPWord's
// own text-rotation support is unreliable. This Node environment has no
// `canvas`/`sharp` to rasterize with — but the `docx` package's
// WpsShapeRun (a native DrawingML text box) supports real rotated TEXT
// natively, anchored `behindDocument` and floating relative to the page,
// which is the more reliable option here (no rasterization step at all).
// Since OOXML text runs have no alpha channel, "opacity" is simulated by
// blending the requested color toward white at the given opacity — the
// same visual effect PHP's GD alpha channel produces against a white page.
// --------------------------------------------------------------------

function blendTowardWhite(hex, opacity) {
  const clean = String(hex || '#C0392B').replace('#', '');
  const r = parseInt(clean.substring(0, 2), 16) || 0;
  const g = parseInt(clean.substring(2, 4), 16) || 0;
  const b = parseInt(clean.substring(4, 6), 16) || 0;
  const o = Math.max(0, Math.min(1, opacity));
  const mix = (c) => Math.round(c * o + 255 * (1 - o));
  return [mix(r), mix(g), mix(b)].map((v) => v.toString(16).padStart(2, '0')).join('').toUpperCase();
}

function watermarkHeader(watermark) {
  if (!watermark || !watermark.enabled || !watermark.text) return null;
  const color = blendTowardWhite(watermark.color || '#C0392B', watermark.opacity ?? 0.15);
  const fontSizePx = Number(watermark.font_size || 60);
  const angle = Number(watermark.angle ?? 45);
  const sizeHalfPt = Math.max(20, Math.min(120, Math.round(fontSizePx * 1.2)));

  // A wide, roughly page-sized box (not just a tight box around the glyphs)
  // so the rotated text's diagonal actually crosses the page center rather
  // than sitting as a small centered cluster — closer to how a real Word
  // watermark reads at a glance.
  const boxWidthPt = 520;
  const boxHeightPt = 200;

  const shapeRun = new WpsShapeRun({
    type: 'wps',
    children: [
      new Paragraph({
        alignment: AlignmentType.CENTER,
        children: [run(watermark.text, { bold: true, size: sizeHalfPt, color })],
      }),
    ],
    transformation: {
      width: ptToPx(boxWidthPt),
      height: ptToPx(boxHeightPt),
      rotation: -angle,
    },
    floating: {
      horizontalPosition: { relative: 'page', align: 'center' },
      verticalPosition: { relative: 'page', align: 'center' },
      behindDocument: true,
      allowOverlap: true,
      wrap: { type: TextWrappingType.NONE },
    },
  });

  return new Header({ children: [new Paragraph({ children: [shapeRun] })] });
}

module.exports = {
  // colors
  NAVY,
  NAVY_MID,
  GRAY_LIGHT,
  BORDER_GRAY,
  MUTED,
  SPEC_BG,
  WHITE,
  BLACK,
  AMBER_BG,
  AMBER_BG2,
  AMBER_BORDER,
  AMBER_LABEL,
  AMBER_VALUE,
  AMBER_NOTE,
  AMBER_TEXT2,
  GREEN_BG,
  GREEN_BORDER,
  GREEN_VALUE,
  RED_BG,
  RED_BORDER,
  RED_TEXT,
  RED_SUB,
  BLUE_BG,
  BLUE_BORDER,
  BLUE_BG2,
  BLUE_BORDER2,
  BLUE_TEXT,
  FONT,
  PAGE_A4,
  TABLE_MARGINS,
  FULL_WIDTH,
  // helpers
  shade,
  thinBorders,
  NO_BORDERS,
  pctWidth,
  run,
  rich,
  plain,
  spacer,
  cell,
  renderValue,
  decodeDataUri,
  readImageSize,
  imageAtHeight,
  imageInBox,
  documentDefaultStyles,
  header,
  mandatoryNote,
  metaBar,
  sectionTitle,
  colorBox,
  kvTable,
  productsTable,
  totalsTable,
  checklistTable,
  stepBadge,
  threeColFinanceTable,
  bulletList,
  signatureBlock,
  termsSection,
  watermarkHeader,
};
