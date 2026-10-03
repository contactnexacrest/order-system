'use strict';

/**
 * Converts the sanitized "Additional Terms" HTML (order_annexure_terms.
 * content_html, already passed through annexureTermsSanitizer at both save
 * and read time) into docx.js elements for Annexure A's DOCX output.
 *
 * PHP's sibling (DocxDocumentBuilder::addAnnexureBody()) gets this for
 * free from PhpWord's built-in \PhpOffice\PhpWord\Shared\Html::addHtml().
 * The `docx` npm package has no equivalent HTML importer, so this module
 * is the Node port of that one PHP library call — a small, hand-rolled
 * converter rather than a general HTML-to-DOCX engine, because it only
 * ever has to understand the exact tag/attribute allowlist
 * annexureTermsSanitizer already enforces (p, br, strong/b, em/i, u, s/
 * strike, h3/h4, blockquote, ul/ol/li, table/thead/tbody/tr/td/th,
 * span[style], a[href], img[src]) — nothing else can appear in its input.
 *
 * Bullet/numbered lists render as plain paragraphs with a bullet glyph or
 * "N." prefix and a hanging indent rather than real Word list numbering:
 * native numbering in docx.js requires a numbering definition registered
 * on the Document itself, which this module has no access to (it only
 * returns an array of section children, mirroring every other render*()
 * helper in docxDocumentBuilder.js) — a cosmetic simplification, not a
 * functional gap.
 */

const { parse } = require('node-html-parser');
const { Paragraph, TextRun, Table, TableRow, TableCell, AlignmentType, VerticalAlign, ExternalHyperlink, BorderStyle } = require('docx');
const C = require('./docxComponents');

function convertAnnexureTermsHtml(html) {
  if (!html) return [];
  const root = parse(String(html));
  const out = [];
  let olCounter = 0;
  for (const node of root.childNodes) {
    out.push(...renderBlock(node, { olCounter: () => ++olCounter }));
  }
  return out;
}

function renderBlock(node, ctx) {
  if (node.nodeType === 3) {
    // Stray top-level text node (sanitizer allows only block tags at the
    // root in practice, but handle it rather than drop content silently).
    const text = node.text.trim();
    return text ? [C.plain(text)] : [];
  }
  if (node.nodeType !== 1) return [];

  const tag = (node.rawTagName || '').toLowerCase();
  switch (tag) {
    case 'p':
      return [new Paragraph({ children: renderInline(node), spacing: { after: 100 } })];
    case 'h3':
      return [new Paragraph({ children: renderInline(node, { bold: true, size: 22 }), spacing: { before: 160, after: 80 } })];
    case 'h4':
      return [new Paragraph({ children: renderInline(node, { bold: true, size: 20 }), spacing: { before: 120, after: 60 } })];
    case 'blockquote':
      return [
        new Paragraph({
          children: renderInline(node, { italics: true, color: '4A5568' }),
          indent: { left: 360 },
          border: { left: { style: BorderStyle.SINGLE, size: 12, color: 'D7DEE6', space: 8 } },
          spacing: { after: 100 },
        }),
      ];
    case 'ul':
      return node.childNodes
        .filter((li) => (li.rawTagName || '').toLowerCase() === 'li')
        .map((li) => new Paragraph({ children: [C.run('•  ', {}), ...renderInline(li)], indent: { left: 360, hanging: 260 }, spacing: { after: 60 } }));
    case 'ol':
      return node.childNodes
        .filter((li) => (li.rawTagName || '').toLowerCase() === 'li')
        .map((li) => new Paragraph({ children: [C.run(`${ctx.olCounter()}.  `, {}), ...renderInline(li)], indent: { left: 360, hanging: 260 }, spacing: { after: 60 } }));
    case 'table':
      return [renderTable(node)];
    default:
      // Any other allowlisted inline tag landing directly at block level
      // (sanitize-html/HTMLPurifier wouldn't normally leave one unwrapped,
      // but treat it as its own paragraph rather than dropping it).
      return [new Paragraph({ children: renderInline(node) })];
  }
}

/** @returns {Array<TextRun|ImageRun|ExternalHyperlink>} */
function renderInline(node, inherited = {}) {
  const runs = [];
  for (const child of node.childNodes) {
    runs.push(...renderInlineNode(child, inherited));
  }
  return runs.length ? runs : [C.run('', inherited)];
}

function renderInlineNode(node, inherited) {
  if (node.nodeType === 3) {
    const text = node.text;
    return text ? [C.run(text, inherited)] : [];
  }
  if (node.nodeType !== 1) return [];

  const tag = (node.rawTagName || '').toLowerCase();
  switch (tag) {
    case 'br':
      return [new TextRun({ text: '', break: 1 })];
    case 'strong':
    case 'b':
      return node.childNodes.flatMap((c) => renderInlineNode(c, { ...inherited, bold: true }));
    case 'em':
    case 'i':
      return node.childNodes.flatMap((c) => renderInlineNode(c, { ...inherited, italics: true }));
    case 'u':
      return node.childNodes.flatMap((c) => renderInlineNode(c, { ...inherited, underline: {} }));
    case 's':
    case 'strike':
      return node.childNodes.flatMap((c) => renderInlineNode(c, { ...inherited, strike: true }));
    case 'span': {
      const style = parseInlineStyle(node.getAttribute('style') || '');
      return node.childNodes.flatMap((c) => renderInlineNode(c, { ...inherited, ...style }));
    }
    case 'a': {
      const href = node.getAttribute('href');
      const children = node.childNodes.flatMap((c) => renderInlineNode(c, { ...inherited, color: inherited.color || '013090', underline: {} }));
      if (!href) return children;
      return [new ExternalHyperlink({ link: href, children })];
    }
    case 'img': {
      const src = node.getAttribute('src');
      const img = src ? C.imageInBox(src, 320, 220) : null;
      return img ? [img] : [];
    }
    default:
      return node.childNodes.flatMap((c) => renderInlineNode(c, inherited));
  }
}

function parseInlineStyle(styleAttr) {
  const style = {};
  styleAttr.split(';').forEach((decl) => {
    const [prop, value] = decl.split(':').map((s) => (s || '').trim());
    if (!prop || !value) return;
    if (prop === 'color') style.color = cssColorToHex(value);
    // background-color: docx.js's run-level "highlight" only accepts a
    // fixed set of named swatches (not arbitrary hex), so an arbitrary
    // sanitized background-color is dropped rather than misrendered.
    if (prop === 'font-weight' && (value === 'bold' || parseInt(value, 10) >= 600)) style.bold = true;
    if (prop === 'font-style' && value === 'italic') style.italics = true;
    if (prop === 'text-decoration' && value.includes('underline')) style.underline = {};
    if (prop === 'text-decoration' && value.includes('line-through')) style.strike = true;
  });
  return style;
}

function cssColorToHex(value) {
  if (/^#[0-9a-fA-F]{6}$/.test(value)) return value.slice(1).toUpperCase();
  if (/^#[0-9a-fA-F]{3}$/.test(value)) {
    return value.slice(1).split('').map((c) => c + c).join('').toUpperCase();
  }
  return undefined; // named CSS colors (e.g. "red") aren't worth a lookup table here; default run color applies.
}

function renderTable(node) {
  const rows = node.querySelectorAll('tr');
  const tableRows = rows.map((tr) => {
    const cells = tr.querySelectorAll('td, th');
    const isHeaderRow = cells.length > 0 && cells.every((c) => (c.rawTagName || '').toLowerCase() === 'th');
    const width = C.pctWidth(100 / Math.max(1, cells.length));
    return new TableRow({
      children: cells.map(
        (cellNode) =>
          new TableCell({
            width,
            borders: C.thinBorders(),
            verticalAlign: VerticalAlign.TOP,
            margins: C.TABLE_MARGINS,
            children: [new Paragraph({ children: renderInline(cellNode, isHeaderRow ? { bold: true } : {}) })],
          })
      ),
    });
  });
  return new Table({ width: C.FULL_WIDTH, rows: tableRows });
}

module.exports = { convertAnnexureTermsHtml };
