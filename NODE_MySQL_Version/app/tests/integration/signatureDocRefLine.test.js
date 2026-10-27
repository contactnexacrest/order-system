'use strict';

const path = require('path');
const nunjucks = require('nunjucks');
const markdownLite = require('../../src/helpers/markdownLite');
const docxComponents = require('../../src/services/docx/docxComponents');

function templateEnv() {
  const env = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '../../templates')));
  env.addFilter('split', (str, sep, limit) => String(str).split(sep, limit));
  env.addFilter('nl2br', (v) => v);
  env.addFilter('money', (v) => Number(v).toFixed(2));
  env.addFilter('rate4', (v) => Number(v).toFixed(4));
  env.addFilter('maskEmail', (v) => v);
  env.addFilter('maskPhone', (v) => v);
  env.addFilter('markdown_lite', (v) => new nunjucks.runtime.SafeString(markdownLite.toHtml(v)));
  return env;
}

function minimalDocContext() {
  return {
    company: {}, assets: {}, watermark: { enabled: false },
    meta: { document_reference: 'QT/2026/00123', generated_date: '21 October 2026' },
    order: { quotation_ref: 'QT-1', buyer_inquiry_ref: 'NC/SC/2026/0099' },
    financial: { advance_pct: 30 },
    terms: [], terms_section_number: 9, terms_section_title: 'TERMS & CONDITIONS',
    doc_title: 'QUOTATION', products: [], buyer: {}, signatory: {},
  };
}

/** Finds every w:t text node inside a docx.js element's internal tree and concatenates its content. */
function extractText(node) {
  if (!node) return '';
  if (node.rootKey === 'w:t' && Array.isArray(node.root)) {
    return node.root.filter((part) => typeof part === 'string').join('');
  }
  if (Array.isArray(node.root)) {
    return node.root.map(extractText).join('');
  }
  return '';
}

/**
 * Port of SignatureDocRefLineTest.php. 90-item backlog Phase 6 Item C —
 * a signature block/table must always carry real, document-identifying
 * text (document type + reference + date + Buyer Inquiry Ref), never
 * just a bare seal/signature with no way to tell which document it
 * belongs to. Covers both the shared _layout.njk signature_block (most
 * document types) and the BUYERPO/SUPPO templates' own separate
 * acceptance tables.
 */
describe('Signature doc-ref line (90-item backlog Phase 6 Item C)', () => {
  it('CI template signature block carries the doc-ref line', () => {
    const html = templateEnv().render('CI/commercial_invoice.njk', minimalDocContext());
    expect(html).toContain('This signature forms part of QUOTATION QT/2026/00123, dated 21 October 2026, Buyer Inquiry Ref NC/SC/2026/0099.');
  });

  it('BUYERPO acceptance table carries the doc-ref line', () => {
    const html = templateEnv().render('BUYERPO/buyer_po.njk', minimalDocContext());
    expect(html).toContain('This signature forms part of QUOTATION QT/2026/00123, dated 21 October 2026, Buyer Inquiry Ref NC/SC/2026/0099.');
  });

  it('SUPPO acceptance table carries the doc-ref line', () => {
    const html = templateEnv().render('SUPPO/supplier_po.njk', minimalDocContext());
    expect(html).toContain('This signature forms part of QUOTATION QT/2026/00123, dated 21 October 2026, Buyer Inquiry Ref NC/SC/2026/0099.');
  });

  it('doc-ref line omits missing pieces gracefully', () => {
    const context = minimalDocContext();
    context.meta = {};
    context.order = {};
    const html = templateEnv().render('CI/commercial_invoice.njk', context);
    expect(html).toContain('This signature forms part of QUOTATION.');
  });

  it('docxComponents.signatureDocRefLine produces the expected text', () => {
    const paragraph = docxComponents.signatureDocRefLine({
      doc_title: 'PURCHASE ORDER',
      meta: { document_reference: 'PO/2026/00045', generated_date: '21 October 2026' },
      order: { buyer_inquiry_ref: 'NC/SC/2026/0050' },
    });

    expect(extractText(paragraph)).toBe('This signature forms part of PURCHASE ORDER PO/2026/00045, dated 21 October 2026, Buyer Inquiry Ref NC/SC/2026/0050.');
  });

  it('docxComponents.signatureBlock includes the doc-ref line as its first element', () => {
    const elements = docxComponents.signatureBlock({
      doc_title: 'QUOTATION',
      meta: { document_reference: 'QT/2026/00123', generated_date: '21 October 2026' },
      order: { buyer_inquiry_ref: 'NC/SC/2026/0099' },
      company: {}, signatory: {},
    });

    const combinedText = elements.map(extractText).join(' ');
    expect(combinedText).toContain('This signature forms part of QUOTATION QT/2026/00123, dated 21 October 2026, Buyer Inquiry Ref NC/SC/2026/0099.');
  });
});
