'use strict';

/**
 * Per-document-type DOCX composition — the Node sibling of
 * PHP_MySQL_Version's DocxDocumentBuilder.php. Each render*() function
 * mirrors its own Nunjucks template (app/templates/{TYPE}/*.njk)
 * section-by-section, built out of docxComponents.js's shared toolkit —
 * see that module for the color/style constants translated from
 * _layout.njk's CSS.
 *
 * AMD is intentionally not handled here — it has no DOCX generation path
 * (see documentGenerationService.generateAmendment(), PDF-only).
 *
 * Unlike PHPWord (which defaults to NOT escaping "&" etc. and has to be
 * told to via Settings::setOutputEscapingEnabled(true)), the `docx`
 * package always XML-escapes TextRun `text` — there is no equivalent
 * opt-in/opt-out switch, and no equivalent bug to guard against here.
 * Verified directly against a generated .docx containing "TERMS &
 * CONDITIONS" / "Founder & Managing Director" — both escape to `&amp;` in
 * word/document.xml and the file opens cleanly.
 */

const { Document, Paragraph, Table, TableRow, AlignmentType, VerticalAlign } = require('docx');
const C = require('./docxComponents');

// ------------------------------------------------------------------
// Small local helpers (mirrors PHP's DocxDocumentBuilder::g()/titleFor()/etc.)
// ------------------------------------------------------------------

function g(obj, path, fallback = '—') {
  let cur = obj;
  for (const key of path.split('.')) {
    if (cur === null || typeof cur !== 'object' || !(key in cur)) return fallback;
    cur = cur[key];
  }
  return cur === null || cur === undefined ? fallback : cur;
}

function titleFor(code) {
  const map = {
    QT: 'QUOTATION',
    ANNEXA: 'ANNEXURE A — PRODUCT TECHNICAL SPECIFICATIONS',
    PI: 'PROFORMA INVOICE',
    OC: 'ORDER CONFIRMATION',
    BUYERPO: 'PURCHASE ORDER — ORDER ACCEPTANCE',
    SUPPO: 'PURCHASE ORDER — MATERIAL PROCUREMENT',
    FDN: 'FREIGHT DEBIT NOTE',
    PL: 'PACKING LIST',
    BLI: 'BILL OF LADING INSTRUCTION SHEET',
    CI: 'COMMERCIAL INVOICE',
    COOPREP: 'COO PREPARATION SHEET',
  };
  return map[code] || code;
}

/**
 * The kv-table checkbox-row look (BLI Sections 5/7). Plain ASCII brackets
 * rather than Unicode ballot-box glyphs (☑/☐) — those glyphs are
 * missing from Carlito and render as tofu boxes in LibreOffice/Word for
 * the (very common) unchecked state, so "[X]" / "[ ]" is the more
 * reliable real-Word-formatting equivalent of the CSS .cbx checkbox
 * square (same reasoning as the PHP build).
 */
function checkbox(checked, label) {
  return `${checked ? '[X] ' : '[ ] '}${label}`;
}

function starRun() {
  return ['*', { bold: true, color: 'C0392B' }];
}

/** Bank Details kv rows — reused verbatim by PI/FDN/CI Section "BANK DETAILS". */
function bankDetailsRows(context, paymentRefLabel, rbiCode) {
  const company = context.company || {};
  return [
    { label: 'Account Holder', value: String(g(company, 'legal_name', '')).toUpperCase() },
    { label: 'Bank', value: g(company, 'bank_name') },
    { label: 'Branch', value: g(company, 'bank_branch') },
    { label: 'Account No.', value: g(company, 'bank_account_no') },
    { label: 'IFSC', value: g(company, 'ifsc') },
    { label: 'SWIFT / BIC', value: g(company, 'swift_bic') },
    { label: 'Bank Address', value: g(company, 'bank_address') },
    { label: 'Payment Reference', value: paymentRefLabel },
    { label: 'RBI Purpose Code', value: rbiCode },
  ];
}

function documentsProvidedParagraphs(heading, lines) {
  return [C.rich([[heading, { bold: true }]]), ...lines.map((line) => C.plain(line))];
}

/** Standard "1. {title}" seller/exporter block shared by QT/PI/OC/SUPPO/FDN/CI/PL. */
function defaultSection1(context, title) {
  const company = context.company || {};
  return [
    ...C.sectionTitle(`1. ${title}`),
    ...C.kvTable([
      { label: 'Company / Legal Entity', value: g(company, 'legal_name') },
      { label: 'Registered Office', value: g(company, 'registered_office') },
      { label: 'Corporate Office', value: g(company, 'corporate_office') },
      { label: 'GSTIN', value: g(company, 'gstin') },
      { label: 'IEC / PAN', value: g(company, 'iec_pan') },
      { label: 'Contact Person', value: `${g(company, 'md_name')} — ${g(company, 'md_title')}` },
      { label: 'Phone', value: g(company, 'phone') },
      { label: 'Email', value: g(company, 'email') },
    ]),
  ];
}

function productTableHeaders(currency) {
  return ['#', 'Product Description *', 'Qty *', 'Unit *', `Unit Price (${currency}) *`, `Amount (${currency}) *`];
}

/** Builds the shared 6-col product table + spec sub-row rows (QT/PI/OC/CI all share this exact schema). */
function buildProductRows(products) {
  return (products || []).map((p, i) => {
    const lineColor = i % 2 === 0 ? '1D6FA8' : '2D7D56';
    const n = i + 1;
    const specText =
      p.dimensions || p.finish
        ? [
            [`${n}. `, { bold: true, color: lineColor }],
            ['Dimensions: ', { bold: true, color: C.NAVY }],
            [`${p.dimensions || 'TBC'}   ·   `, {}],
            ['Finish: ', { bold: true, color: C.NAVY }],
            [`${p.finish || 'TBC'}   ·   `, {}],
            ['HS Code: ', { bold: true, color: C.NAVY }],
            [`${p.hs_code || ''}   ·   `, {}],
            ['Country of Origin: ', { bold: true, color: C.NAVY }],
            ['India', {}],
          ]
        : null;
    return {
      cells: [String(n), p.description || '', p.quantity ?? '', p.unit || '', p.unit_price ?? '', p.amount ?? ''],
      lineColor,
      align: [null, null, 'right', null, 'right', 'right'],
      specText,
    };
  });
}

function weightTable(order) {
  const out = [C.plain('Weight & Volume (Estimated — actuals confirmed on Packing List after production)', { bold: true, size: 21, color: C.NAVY })];
  const rows = [
    ['Total CBM (m³)', g(order, 'estimated_total_cbm', 'TBC'), 'm³'],
    ['Gross Weight (incl. packing)', g(order, 'estimated_gross_weight_kg', 'TBC'), 'kg'],
    ['Net Weight (stone only)', g(order, 'estimated_net_weight_kg', 'TBC'), 'kg'],
    ['No. of Packages / Crates', g(order, 'estimated_package_count', 'TBC'), 'Crates'],
    ['Package Type', g(order, 'estimated_package_type', 'TBC'), '—'],
  ];
  out.push(...C.checklistTable(['Parameter', 'Estimated Value *', 'Unit'], [50, 35, 15], rows.map((r) => ({ cells: r.map(String) }))));
  return out;
}

function paymentTermsBlock(context) {
  const financial = context.financial || {};
  const order = context.order || {};
  const currency = g(order, 'currency_code', '');
  const out = [];
  out.push(C.rich([['• ', {}], [`${g(financial, 'advance_pct', '')}% advance T/T on FOB Value against Proforma Invoice. `, {}], starRun()]));
  out.push(C.rich([[`   ${g(financial, 'advance_pct', '')}% Advance Amount:  ${currency} ${g(financial, 'advance_amount', '')}`, {}], starRun()]));
  const balanceText =
    g(financial, 'balance_trigger_option', '') === 'A_BEFORE_SHIPMENT'
      ? `payable before shipment — within ${g(financial, 'balance_days', '')} working days of receiving shipment readiness confirmation from NexaCrest.`
      : `payable against scanned copy of Bill of Lading, within ${g(financial, 'balance_days', '')} days of BL date.`;
  out.push(C.rich([['• ', {}], [`${g(financial, 'balance_pct', '')}% balance T/T on FOB Value ${balanceText} `, {}], starRun()]));
  out.push(C.rich([[`   ${g(financial, 'balance_pct', '')}% Balance Amount:  ${currency} ${g(financial, 'balance_amount', '')}`, {}], starRun()]));
  if (!order.is_fob) {
    out.push(
      C.rich([
        ['• Freight & Insurance (CFR/CIF orders only): ', {}],
        ['Actual confirmed amounts invoiced separately by Freight Debit Note before shipment booking is confirmed. Payment required within 3 working days of Freight Debit Note date.', { color: '333333' }],
      ])
    );
  }
  out.push(C.plain(`   Currency: ${currency}`));
  return out;
}

// ==================================================================
// QT — Quotation
// ==================================================================
function renderQt(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const financial = context.financial || {};
  const currency = g(order, 'currency_code', '');
  const children = [];

  children.push(...C.header(context, titleFor('QT'), g(order, 'incoterm_code', null) ? `${g(order, 'incoterm_code')} ${String(g(order, 'port_of_loading', '')).toUpperCase()}` : null));
  children.push(C.mandatoryNote());
  children.push(
    ...C.metaBar([
      { label: 'QUOTATION NO. *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'DATE *', value: g(order, 'quotation_date') !== '—' ? g(order, 'quotation_date') : g(meta, 'generated_date', '') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );
  children.push(
    ...C.colorBox(C.AMBER_BG, C.AMBER_BORDER, [
      C.rich([
        ['⏱  ', { size: 19 }],
        ['VALID UNTIL * ', { bold: true, color: C.AMBER_LABEL, size: 19 }],
        [` ${g(order, 'quotation_valid_until', 'TBC')}  `, { bold: true, size: 22, color: C.AMBER_VALUE }],
        ['Prices and terms are not valid after this date.', { italics: true, size: 17, color: C.AMBER_NOTE }],
      ]),
    ])
  );

  children.push(...defaultSection1(context, 'SELLER / EXPORTER'));

  children.push(...C.sectionTitle('2. BUYER / CONSIGNEE DETAILS'));
  children.push(
    ...C.kvTable([
      { label: 'Company Legal Name *', value: g(buyer, 'company_legal_name') },
      { label: 'Billing Address *', value: g(buyer, 'billing_address') },
      { label: 'Consignee Name *', value: g(buyer, 'consignee_name') },
      { label: 'Consignee Address *', value: g(buyer, 'consignee_address') },
      { label: 'VAT / EORI / Tax Reg. No. *', value: g(buyer, 'vat_eori_tax_no', 'TBC') },
      { label: 'Country of Destination *', value: g(buyer, 'country_of_destination', 'TBC') },
      { label: 'Certificate of Origin Type', value: g(order, 'coo_type') },
      { label: 'Contact Person *', value: g(buyer, 'contact_person', 'TBC') },
      { label: 'Email *', value: g(buyer, 'email', 'TBC') },
      { label: 'Phone', value: g(buyer, 'phone', '—') },
      { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
    ])
  );

  children.push(...C.sectionTitle('3. PRODUCT / ORDER DETAILS'));
  children.push(...C.productsTable(productTableHeaders(currency), [6, 34, 10, 10, 20, 20], buildProductRows(context.products)));

  const freightText = order.is_fob
    ? 'NIL — freight arranged by buyer.'
    : `Indicative approx. ${currency} ${g(order, 'indicative_freight_low', 'XXX')}–${g(order, 'indicative_freight_high', 'XXX')} per ${g(order, 'container_type')}, ${g(order, 'port_of_loading')} to ${g(order, 'port_of_discharge')}. Subject to confirmation at time of booking. Actual freight confirmed and recovered IN ADVANCE by Freight Debit Note when cargo is packed and ready — payment required within 3 working days, and must be received BEFORE shipment booking is confirmed.`;
  const insuranceText = order.is_fob ? "NIL — buyer's responsibility." : `Indicative approx. ${currency} ${g(order, 'indicative_insurance_amount', 'XX')}. Confirmed by Freight Debit Note when cargo is ready.`;
  children.push(
    ...C.totalsTable([
      { label: `FOB Value (${currency}) *`, value: g(financial, 'fob_value') },
      { label: `Freight (${currency})`, value: freightText },
      { label: `Insurance (${currency})`, value: insuranceText },
      { label: `TOTAL QUOTED VALUE (${currency})`, value: g(financial, 'total_value'), highlight: true },
    ])
  );

  children.push(...weightTable(order));

  children.push(...C.sectionTitle('4. SHIPPING / COMMERCIAL TERMS'));
  children.push(
    ...C.kvTable([
      { label: 'Incoterm *', value: g(order, 'incoterm_label') },
      { label: 'Port of Loading *', value: g(order, 'port_of_loading') },
      { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
      { label: 'Container Type', value: g(order, 'container_type') },
      { label: 'Est. Lead Time', value: g(order, 'est_lead_time_text') },
      { label: 'Insurance', value: order.is_fob ? "Buyer's responsibility." : 'Seller arranges — cost included in invoice.' },
    ])
  );

  children.push(...C.sectionTitle('5. PAYMENT TERMS'));
  children.push(...paymentTermsBlock(context));

  children.push(...C.sectionTitle('6. DOCUMENTS TO BE PROVIDED'));
  children.push(
    ...documentsProvidedParagraphs('Upon shipment, the following documents will be provided:', [
      '1.  Commercial Invoice (signed and stamped)',
      '2.  Packing List (signed and stamped)',
      `3.  Certificate of Origin — ${g(order, 'coo_type')} — Issued by CAPEXIL`,
      `4.  Bill of Lading — Original Negotiable, 3 originals — Released to buyer only after ${g(financial, 'balance_pct')}% balance T/T is received and cleared`,
      '5.  Fumigation Certificate — provided as standard with every shipment',
    ])
  );

  if (order.special_requirements) {
    children.push(...C.sectionTitle('SPECIAL REQUIREMENTS'));
    children.push(...C.kvTable([{ full: true, value: String(order.special_requirements) }]));
  }

  children.push(...C.termsSection(context, context.terms_section_number ?? 7, context.terms_section_title || 'TERMS & CONDITIONS'));
  children.push(...annexureAppendixIfAny(context));
  children.push(...C.signatureBlock(context));

  return children;
}

// ==================================================================
// PI — Proforma Invoice
// ==================================================================
function renderPi(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const financial = context.financial || {};
  const company = context.company || {};
  const currency = g(order, 'currency_code', '');
  const children = [];

  children.push(...C.header(context, titleFor('PI'), g(order, 'incoterm_code', null) ? `${g(order, 'incoterm_code')} ${String(g(order, 'port_of_loading', '')).toUpperCase()}` : null));
  children.push(C.mandatoryNote());
  children.push(
    ...C.metaBar([
      { label: 'PI NUMBER *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'PI DATE *', value: g(order, 'pi_date') !== '—' ? g(order, 'pi_date') : g(meta, 'generated_date', '') },
      { label: 'QUOTATION REF *', value: g(order, 'quotation_ref', '—') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );
  children.push(
    ...C.colorBox(C.AMBER_BG, C.AMBER_BORDER, [
      C.rich([
        ['⏱  ', { size: 19 }],
        ['VALID UNTIL * ', { bold: true, color: C.AMBER_LABEL, size: 19 }],
        [` ${g(order, 'pi_valid_until', 'TBC')}  `, { bold: true, size: 22, color: C.AMBER_VALUE }],
        ['Payment must be received before this date for prices and terms to remain valid.', { italics: true, size: 17, color: C.AMBER_NOTE }],
      ]),
    ])
  );
  children.push(
    ...C.colorBox(C.GREEN_BG, C.GREEN_BORDER, [
      C.rich([
        ['GST DECLARATION: ', { bold: true, color: C.GREEN_BORDER, size: 19 }],
        ['Supply meant for export under Letter of Undertaking (LUT) without payment of Integrated Tax (IGST). ', { color: C.GREEN_VALUE, size: 19 }],
        [`LUT Order No.: ${g(company, 'lut_number')}`, { bold: true, color: C.GREEN_BORDER, size: 19 }],
        [`   ·   Valid for ${g(company, 'lut_valid_fy')}   ·   GSTIN: ${g(company, 'gstin')}`, { color: C.GREEN_VALUE, size: 19 }],
      ]),
    ])
  );

  children.push(...defaultSection1(context, 'SELLER / EXPORTER'));

  children.push(...C.sectionTitle('2. BUYER / CONSIGNEE DETAILS'));
  children.push(
    ...C.kvTable([
      { label: 'Company Legal Name *', value: g(buyer, 'company_legal_name') },
      { label: 'Billing Address *', value: g(buyer, 'billing_address') },
      { label: 'Consignee Name *', value: g(buyer, 'consignee_name') },
      { label: 'Consignee Address *', value: g(buyer, 'consignee_address') },
      { label: 'VAT / EORI / Tax Reg. No. *', value: g(buyer, 'vat_eori_tax_no', 'TBC') },
      { label: 'Country of Final Destination *', value: g(buyer, 'country_of_destination', 'TBC') },
      { label: 'Certificate of Origin Type', value: g(order, 'coo_type') },
      { label: "Buyer's PO / Ref No.", value: g(order, 'buyers_po_ref') },
      { label: 'Contact Person *', value: g(buyer, 'contact_person', 'TBC') },
      { label: 'Email *', value: g(buyer, 'email', 'TBC') },
      { label: 'Phone', value: g(buyer, 'phone', '—') },
      { label: 'Notify Party', value: g(buyer, 'notify_party', 'SAME as buyer') },
    ])
  );

  children.push(...C.sectionTitle('3. PRODUCT / ORDER DETAILS'));
  const products = context.products || [];
  children.push(...C.productsTable(productTableHeaders(currency), [6, 34, 10, 10, 20, 20], buildProductRows(products)));

  const piTotalsRows = products.map((p) => ({ label: `${p.description || ''} (${p.quantity ?? ''} ${p.unit || ''} × ${p.unit_price ?? ''})`, value: `${currency} ${p.amount ?? ''}` }));
  piTotalsRows.push({ label: `TOTAL PI VALUE (${currency}) *`, value: g(financial, 'fob_value'), highlight: true });
  children.push(...C.totalsTable(piTotalsRows, 65));
  children.push(
    C.rich([
      ['Remark: ', { bold: true, color: C.NAVY }],
      ['Loading quantity subject to final packing confirmation.', { italics: true, color: C.MUTED }],
    ])
  );

  const freightText = order.is_fob
    ? 'NIL — freight arranged by buyer.'
    : `Indicative approx. ${currency} ${g(order, 'indicative_freight_low', 'XXX')}–${g(order, 'indicative_freight_high', 'XXX')} per ${g(order, 'container_type')}, ${g(order, 'port_of_loading')} to ${g(order, 'port_of_discharge')}. Subject to confirmation at time of booking. Actual freight confirmed and recovered IN ADVANCE by separate Freight Debit Note when cargo is packed and ready — payment required within 3 working days, and must be received BEFORE shipment booking is confirmed.`;
  const insuranceText = order.is_fob ? "NIL — buyer's responsibility." : `Indicative approx. ${currency} ${g(order, 'indicative_insurance_amount', 'XX')}. Confirmed by Freight Debit Note when cargo is ready.`;
  children.push(
    ...C.totalsTable([
      { label: `FOB Value (${currency}) *`, value: g(financial, 'fob_value') },
      { label: `Freight (${currency})`, value: freightText },
      { label: `Insurance (${currency})`, value: insuranceText },
      { label: `TOTAL PI VALUE (${currency})`, value: g(financial, 'total_value'), highlight: true },
    ])
  );

  children.push(...weightTable(order));

  children.push(...C.sectionTitle('4. SHIPPING / COMMERCIAL TERMS'));
  children.push(
    ...C.kvTable([
      { label: 'Incoterm *', value: g(order, 'incoterm_label') },
      { label: 'Port of Loading *', value: g(order, 'port_of_loading') },
      { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
      { label: 'Container Type', value: g(order, 'container_type') },
      { label: 'Est. Shipment Date', value: g(order, 'est_shipment_date_text') },
      { label: 'Insurance', value: order.is_fob ? "Buyer's responsibility." : 'Seller arranges — cost included in invoice.' },
    ])
  );

  children.push(...C.sectionTitle('5. PAYMENT TERMS'));
  children.push(...paymentTermsBlock(context));

  children.push(...C.sectionTitle('6. BANK DETAILS'));
  children.push(
    ...C.kvTable(
      bankDetailsRows(
        context,
        `Please quote PI No. ${g(meta, 'document_reference', '')} in your wire transfer remarks.`,
        `${g(company, 'rbi_purpose_code_advance')} — enter in the "Purpose of Remittance" field of your wire transfer form.`
      )
    )
  );

  children.push(...C.sectionTitle('7. EXPORT DOCUMENTATION'));
  children.push(
    ...documentsProvidedParagraphs('Upon shipment, the following documents will be provided:', [
      '1.  Commercial Invoice (signed and stamped)',
      '2.  Packing List (signed and stamped)',
      `3.  Certificate of Origin — ${g(order, 'coo_type')} — Issued by CAPEXIL`,
      `4.  Bill of Lading — Original Negotiable, 3 originals — Released to buyer only after ${g(financial, 'balance_pct')}% balance T/T is received and cleared in NexaCrest bank account`,
      '5.  Fumigation Certificate — provided as standard with every shipment',
    ])
  );

  children.push(...C.termsSection(context, context.terms_section_number ?? 8, context.terms_section_title || 'TERMS & CONDITIONS'));
  children.push(...annexureAppendixIfAny(context));
  children.push(...C.signatureBlock(context));

  return children;
}

// ==================================================================
// OC — Order Confirmation
// ==================================================================
function renderOc(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const financial = context.financial || {};
  const payment = context.payment_status || {};
  const currency = g(order, 'currency_code', '');
  const children = [];

  children.push(...C.header(context, titleFor('OC'), null));
  children.push(C.mandatoryNote());
  children.push(
    ...C.metaBar([
      { label: 'OC NUMBER *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'DATE *', value: g(meta, 'generated_date', '') },
      { label: 'PI REFERENCE *', value: g(order, 'pi_ref', '—') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );
  children.push(
    ...C.colorBox(C.GREEN_BG, C.GREEN_BORDER, [
      C.plain('✓  ORDER CONFIRMED', { bold: true, size: 26, color: C.GREEN_BORDER }),
      C.plain(`This order is confirmed upon receipt and clearance of ${g(financial, 'advance_pct')}% advance T/T payment against the referenced Proforma Invoice.`, { italics: true, size: 19, color: C.GREEN_VALUE }),
    ])
  );

  children.push(...defaultSection1(context, 'SELLER / EXPORTER'));

  children.push(...C.sectionTitle('2. BUYER / CONSIGNEE DETAILS'));
  children.push(
    ...C.kvTable([
      { label: 'Company Legal Name *', value: g(buyer, 'company_legal_name') },
      { label: 'Billing Address *', value: g(buyer, 'billing_address') },
      { label: 'Consignee Name *', value: g(buyer, 'consignee_name') },
      { label: 'Consignee Address *', value: g(buyer, 'consignee_address') },
      { label: 'Contact Person *', value: g(buyer, 'contact_person', 'TBC') },
      { label: 'Phone *', value: g(buyer, 'phone', '—') },
      { label: 'Email *', value: g(buyer, 'email', 'TBC') },
    ])
  );

  const productList = (context.products || []).map((p) => `${p.description || ''} (Qty: ${p.quantity ?? ''} ${p.unit || ''})`);
  children.push(...C.sectionTitle('3. ORDER SUMMARY'));
  children.push(
    ...C.kvTable([
      { label: 'Quotation No. *', value: g(order, 'quotation_ref', '—') },
      { label: 'Quotation Date *', value: g(order, 'quotation_date', '—') },
      { label: 'Proforma Invoice No. *', value: g(order, 'pi_ref', '—') },
      { label: 'Product(s) *', value: productList.join('; ') },
      { label: 'Total Order Value *', value: `${currency} ${g(financial, 'total_value')}` },
      { label: 'Incoterm', value: g(order, 'incoterm_label') },
      { label: 'Port of Loading', value: g(order, 'port_of_loading') },
      { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
      { label: 'Certificate of Origin', value: g(order, 'coo_type') },
    ])
  );

  children.push(...C.sectionTitle('4. PAYMENT STATUS'));
  const paymentRows = [
    { label: `${g(financial, 'advance_pct')}% Advance (USD) *`, value: g(payment, 'advance_amount') !== '—' ? g(payment, 'advance_amount') : g(financial, 'advance_amount') },
    { label: 'Advance T/T Received On *', value: g(payment, 'advance_remittance_received_at', 'TBC') },
    { label: 'Balance Due (USD) *', value: `${g(payment, 'balance_amount') !== '—' ? g(payment, 'balance_amount') : g(financial, 'balance_amount')}  —  ${g(financial, 'balance_terms_text')}` },
    { label: 'Currency', value: 'USD (always)' },
  ];
  if (!order.is_fob) {
    paymentRows.push({
      label: 'CFR / CIF Freight (if applicable)',
      value: 'A Freight Debit Note will be raised when cargo is packed and ready, stating the actual confirmed freight amount. Payment required within 3 working days of the Debit Note date. NexaCrest will confirm shipment booking and hand over cargo to the shipping line only upon receipt of full freight payment.',
      labelBg: C.RED_BG,
      valueBg: C.RED_BG,
    });
  }
  children.push(...C.kvTable(paymentRows));

  children.push(...C.sectionTitle('5. PRODUCTION & ESTIMATED SHIPMENT'));
  children.push(
    ...C.kvTable([
      {
        full: true,
        bg: C.GRAY_LIGHT,
        value: [
          C.rich([
            ['Production status: ', { bold: true, color: C.NAVY }],
            [`${g(order, 'production_status_text')}   `, {}],
            ['Estimated shipment: ', { bold: true, color: C.NAVY }],
            [g(order, 'est_shipment_date_text'), { italics: true, color: C.MUTED }],
            [' *', { bold: true, color: 'C0392B' }],
          ]),
          C.rich([
            ['Note: ', { bold: true, color: C.NAVY, size: 18 }],
            ['Estimated shipment date is indicative and subject to production completion, packing, and port scheduling. A confirmed Bill of Lading date will be communicated once the shipment is booked.', { italics: true, color: C.MUTED, size: 18 }],
          ]),
        ],
      },
    ])
  );

  children.push(...C.sectionTitle('6. DOCUMENTS TO BE PROVIDED'));
  children.push(
    ...C.kvTable([
      {
        full: true,
        value: [
          C.plain('Upon shipment, the following documents will be provided:', { bold: true }),
          ...['Commercial Invoice', 'Packing List', 'Certificate of Origin (CAPEXIL / Chamber of Commerce)', 'Bill of Lading', 'Fumigation Certificate — provided as standard with every shipment'].map((item) =>
            C.plain(`•  ${item}`)
          ),
        ],
      },
    ])
  );

  children.push(...C.termsSection(context, context.terms_section_number ?? 7, context.terms_section_title || 'ORDER CONDITIONS'));
  children.push(...annexureAppendixIfAny(context));
  children.push(...C.signatureBlock(context));

  return children;
}

// ==================================================================
// ANNEXA — Annexure A (standalone)
// ==================================================================
function annexureBody(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const products = context.annexure_products || [];
  const children = [];

  children.push(
    ...C.colorBox(
      C.AMBER_BG2,
      C.AMBER_BORDER,
      [
        C.rich([
          ['Note: ', { bold: true, color: C.AMBER_BORDER, size: 18 }],
          [
            'Product images and drawings are for reference only and may show optional accessories or decorative elements not included in the quoted price. All binding specifications are as stated in each product section below. Actual product appearance may vary slightly due to the natural characteristics of granite.',
            { italics: true, color: C.AMBER_TEXT2, size: 18 },
          ],
        ]),
      ],
      0
    )
  );

  if (!products.length) {
    children.push(C.plain('No product entries have been added to this Annexure yet.', { italics: true, color: C.MUTED }));
  }

  products.forEach((p, i) => {
    children.push(...C.sectionTitle(`PRODUCT ${i + 1}  —  ${p.name || ''}`));
    const images = p.images || [];
    const firstImage = images[0] && images[0].data_uri;

    const textParas = [];
    if (p.description) textParas.push(C.plain(String(p.description), { bold: true, color: C.NAVY }));
    if (p.finish) textParas.push(C.rich([['Surface Finish: ', { bold: true, color: C.NAVY }], [String(p.finish), {}]]));
    if (p.dimensions) textParas.push(C.rich([['Dimensions: ', { bold: true, color: C.NAVY }], [String(p.dimensions), {}]]));
    if (p.components) textParas.push(C.rich([['Includes: ', { bold: true, color: C.NAVY }], [String(p.components), {}]]));
    if (p.technical_notes) textParas.push(C.plain(String(p.technical_notes)));
    if (!textParas.length) textParas.push(C.plain(''));

    const imgCellChildren = [];
    if (firstImage) {
      const img = C.imageInBox(firstImage, 175, 140);
      if (img) imgCellChildren.push(new Paragraph({ alignment: AlignmentType.CENTER, children: [img] }));
    }
    if (!imgCellChildren.length) imgCellChildren.push(C.plain(''));

    const row = new TableRow({
      children: [
        C.cell(imgCellChildren, { width: C.pctWidth(35), shading: C.shade(C.BLACK), verticalAlign: VerticalAlign.CENTER }),
        C.cell(textParas, { width: C.pctWidth(65), verticalAlign: VerticalAlign.TOP }),
      ],
    });
    children.push(new Table({ width: C.FULL_WIDTH, margins: C.TABLE_MARGINS, borders: C.NO_BORDERS, rows: [row] }));
    children.push(C.spacer(120));

    const extraImages = images.slice(1).filter((img) => img.data_uri);
    if (extraImages.length) {
      const cells = extraImages.map((img) => {
        const im = C.imageInBox(img.data_uri, 90, 65);
        return C.cell([new Paragraph({ alignment: AlignmentType.CENTER, children: im ? [im] : [] })], {
          width: C.pctWidth(100 / extraImages.length),
          borders: C.thinBorders(),
          verticalAlign: VerticalAlign.CENTER,
        });
      });
      children.push(new Table({ width: C.FULL_WIDTH, margins: C.TABLE_MARGINS, rows: [new TableRow({ children: cells })] }));
      children.push(C.spacer(120));
    }
  });

  const refDoc = g(order, 'quotation_ref') !== '—' ? g(order, 'quotation_ref') : g(order, 'pi_ref') !== '—' ? g(order, 'pi_ref') : 'the referenced document';
  children.push(
    ...C.colorBox(
      C.BLUE_BG,
      C.BLUE_BORDER2,
      [
        C.rich([
          [`This Annexure A forms an integral part of ${refDoc} dated ${g(meta, 'generated_date')}. `, { color: C.NAVY, size: 19 }],
          [
            'The commercial terms, pricing, payment conditions, and quantities stated in the main document take precedence in all cases. Product images and technical drawings in this annexure are for reference and identification purposes only. This Annexure is not valid as a standalone document.',
            { italics: true, color: C.BLUE_TEXT, size: 19 },
          ],
        ]),
      ],
      0
    )
  );

  return children;
}

function annexureAppendixIfAny(context) {
  if (!(context.order || {}).include_annexure_a || !(context.annexure_products || []).length) return [];
  return [new Paragraph({ children: [], pageBreakBefore: true }), ...C.sectionTitle('ANNEXURE A  —  PRODUCT TECHNICAL SPECIFICATIONS'), ...annexureBody(context)];
}

function renderAnnexa(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const children = [];
  children.push(...C.header(context, titleFor('ANNEXA'), null));
  children.push(C.mandatoryNote());
  children.push(
    ...C.metaBar([
      { label: 'ANNEXURE TO', value: g(order, 'quotation_ref') !== '—' ? g(order, 'quotation_ref') : g(order, 'pi_ref') !== '—' ? g(order, 'pi_ref') : g(order, 'buyer_inquiry_ref') },
      { label: 'DATE', value: g(meta, 'generated_date', '') },
      { label: 'BUYER INQUIRY REF', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );
  children.push(...annexureBody(context));
  children.push(...C.signatureBlock(context));
  return children;
}

// ==================================================================
// BUYERPO — Purchase Order (Order Acceptance, issued to buyer)
// ==================================================================
function renderBuyerPo(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const financial = context.financial || {};
  const signatory = context.signatory || {};
  const company = context.company || {};
  const products = context.products || [];
  const currency = g(order, 'currency_code', '');
  const children = [];

  children.push(...C.header(context, titleFor('BUYERPO'), 'ORDER ACCEPTANCE — Issued to Buyer for Signature and Return'));
  children.push(C.plain('This Purchase Order is prepared by NexaCrest and sent to the buyer for signature and return. Buyer fills Column B of Section 4 only. All other fields are pre-filled by NexaCrest.', { italics: true, color: C.MUTED, size: 18 }));
  children.push(
    ...C.metaBar([
      { label: 'PO NUMBER *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'DATE *', value: g(meta, 'generated_date', '') },
      { label: 'QT REFERENCE *', value: g(order, 'quotation_ref', '—') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );
  children.push(
    ...C.colorBox(C.AMBER_BG, C.AMBER_BORDER, [
      C.rich([
        ['⏱  VALID UNTIL * ', { bold: true, color: C.AMBER_LABEL }],
        [` ${g(order, 'quotation_valid_until', 'TBC')}  `, { bold: true, size: 22, color: C.AMBER_VALUE }],
        ['This Purchase Order must be signed and returned before the Quotation validity expires.', { italics: true, size: 17, color: C.AMBER_NOTE }],
      ]),
    ])
  );

  children.push(...defaultSection1(context, 'SUPPLIER'));

  children.push(...C.sectionTitle('2. BUYER / CONSIGNEE DETAILS  (Pre-filled by NexaCrest — buyer to confirm)'));
  children.push(
    ...C.kvTable([
      { label: 'Company Legal Name *', value: g(buyer, 'company_legal_name') },
      { label: 'Billing Address *', value: g(buyer, 'billing_address') },
      { label: 'Consignee Name *', value: g(buyer, 'consignee_name') },
      { label: 'Consignee Address *', value: g(buyer, 'consignee_address') },
      { label: 'VAT / EORI / Tax Reg. *', value: g(buyer, 'vat_eori_tax_no', 'TBC') },
      { label: 'Contact Person *', value: g(buyer, 'contact_person', 'TBC') },
      { label: 'Phone *', value: g(buyer, 'phone', '—') },
      { label: 'Email *', value: g(buyer, 'email', 'TBC') },
      { label: 'Country of Destination *', value: g(buyer, 'country_of_destination', 'TBC') },
      { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
    ])
  );

  const productList = products.map((p) => p.description || '').join('; ');
  const first = products[0] || {};
  children.push(...C.sectionTitle('3. ORDER DETAILS  (Pre-filled from Quotation — buyer to confirm)'));
  children.push(
    ...C.kvTable([
      { label: 'Against Quotation No. *', value: `${g(order, 'quotation_ref', '—')} (fixed)` },
      { label: 'Quotation Date *', value: `${g(order, 'quotation_date', '—')} (fixed)` },
      { label: 'Product Description *', value: productList },
      { label: 'Quantity *', value: first.quantity ?? 'TBC' },
      { label: 'Unit *', value: first.unit || 'TBC' },
      { label: `Unit Price (${currency}) *`, value: first.unit_price ?? 'TBC' },
      { label: `Total FOB Value (${currency}) *`, value: g(financial, 'fob_value') },
      { label: 'Incoterm', value: g(order, 'incoterm_label') },
      { label: 'Port of Loading', value: g(order, 'port_of_loading') },
      { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
      { label: 'Certificate of Origin *', value: g(order, 'coo_type') },
      {
        label: 'Payment Terms',
        value: `${g(financial, 'advance_pct')}% advance T/T against Proforma Invoice before production. ${g(financial, 'balance_pct')}% balance T/T before shipment — ${g(financial, 'balance_terms_text')} Freight & Insurance (CFR/CIF): invoiced separately by Freight Debit Note before shipment.`,
      },
    ])
  );

  children.push(...C.sectionTitle('4. BUYER ACCEPTANCE  (Buyer fills this section, signs and returns)'));
  const headerRow = new TableRow({
    children: [
      C.cell(C.plain('Prepared & Issued by NexaCrest', { bold: true, color: C.WHITE }), { width: C.pctWidth(50), shading: C.shade(C.NAVY_MID) }),
      C.cell(C.plain('Accepted by Buyer  •  Fill, Sign & Return', { bold: true, color: C.WHITE }), { width: C.pctWidth(50), shading: C.shade(C.NAVY_MID) }),
    ],
  });
  const leftPara = [
    C.plain(String(g(signatory, 'name', '')), { bold: true, color: C.NAVY }),
    C.plain(String(g(signatory, 'designation', ''))),
    C.plain(String(g(company, 'legal_name', '')), { size: 17, color: '555555' }),
  ];
  const rightPara = [
    C.plain('Authorised Signature:', { bold: true }),
    C.plain('_________________________________', { color: '999999' }),
    C.plain('Name: _____________________________'),
    C.plain('Designation: ______________________'),
    C.plain("Buyer's PO No.: __________________  (write NIL if not applicable)"),
    C.plain('Date: _____________________________', { bold: true }),
    C.plain('Company Stamp: (if applicable)', { italics: true, size: 17, color: '888888' }),
  ];
  const bodyRow = new TableRow({
    children: [
      C.cell(leftPara, { width: C.pctWidth(50), borders: C.thinBorders() }),
      C.cell(rightPara, { width: C.pctWidth(50), shading: C.shade('FFFDE7'), borders: C.thinBorders() }),
    ],
  });
  children.push(new Table({ width: C.FULL_WIDTH, margins: C.TABLE_MARGINS, rows: [headerRow, bodyRow] }));
  children.push(C.spacer(120));

  const clauseParas = (context.terms || []).map((clause) => {
    const parts = String(clause).split(': ');
    return C.rich([
      ['•  ', { color: C.BLACK }],
      [`${parts[0] || ''}: `, { bold: true, color: C.BLACK }],
      [parts.slice(1).join(': ') || '', { color: C.BLACK }],
    ]);
  });
  children.push(
    ...C.colorBox('E8F1FB', C.BLUE_TEXT, [
      C.rich([
        ['By signing above, the buyer confirms acceptance of all order details, specifications and payment terms stated in this Purchase Order. ', { color: C.BLUE_TEXT }],
        [`This signed Purchase Order authorises NexaCrest to issue the Proforma Invoice and commence production upon receipt of the ${g(financial, 'advance_pct')}% advance payment. `, { bold: true, color: C.BLUE_TEXT }],
        ['The following clauses are legally binding on both parties:', { color: C.BLACK }],
      ]),
      ...clauseParas,
    ], 0)
  );

  children.push(
    ...C.kvTable([
      {
        full: true,
        bg: C.GRAY_LIGHT,
        value: `This Purchase Order form is prepared by NexaCrest International Private Limited for the exclusive use of the named buyer against Quotation No. ${g(order, 'quotation_ref', '—')} only. It is not transferable and cannot be used with any other supplier. Any unauthorised use or modification of this document is prohibited.`,
      },
    ])
  );

  children.push(...annexureAppendixIfAny(context));

  return children;
}

// ==================================================================
// SUPPO — Purchase Order (Material Procurement, issued to supplier)
// ==================================================================
function renderSupPo(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const sp = context.supplier_po || {};
  const signatory = context.signatory || {};
  const company = context.company || {};
  const green = '1A4A08';
  const children = [];

  children.push(...C.header(context, titleFor('SUPPO'), 'Issued to Supplier for Signature and Return'));
  children.push(C.plain('This Purchase Order is issued by NexaCrest International Private Limited to the named supplier for material procurement. Supplier fills Section 7 only. All other sections are issued by NexaCrest.', { italics: true, color: C.MUTED, size: 18 }));
  children.push(
    ...C.metaBar([
      { label: 'PO NUMBER *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'DATE *', value: g(meta, 'generated_date', '') },
      { label: 'BUYER ORDER REF *', value: g(order, 'buyer_inquiry_ref', '') },
      { label: 'EXPORT ORDER REF *', value: g(order, 'pi_ref') !== '—' ? g(order, 'pi_ref') : g(order, 'quotation_ref', '—') },
    ])
  );

  children.push(...defaultSection1(context, 'BUYER (NexaCrest International Private Limited)'));
  children.push(...C.sectionTitle('2. SUPPLIER DETAILS  (Pre-filled by NexaCrest)', green));
  children.push(
    ...C.kvTable([
      { label: 'Supplier Legal Name *', value: g(sp, 'supplier_legal_name') },
      { label: 'Address *', value: g(sp, 'supplier_address') },
      { label: 'GSTIN *', value: g(sp, 'supplier_gstin', 'TBC') },
      { label: 'PAN', value: g(sp, 'supplier_pan', '—') },
      { label: 'Contact Person *', value: g(sp, 'supplier_contact_person', 'TBC') },
      { label: 'Phone *', value: g(sp, 'supplier_phone', 'TBC') },
      { label: 'Supplier Type *', value: g(sp, 'supplier_type', 'TBC') },
    ])
  );

  children.push(...C.sectionTitle('3. MATERIAL SPECIFICATIONS  (All fields mandatory — no exceptions)', green));
  children.push(
    ...C.colorBox(C.RED_BG, C.RED_BORDER, [
      C.rich([
        ['⚠  All specifications below are binding. ', { bold: true, color: C.RED_TEXT, size: 18 }],
        ["Material that does not conform exactly to the specifications below will be rejected at NexaCrest's discretion. Replacement is at supplier's cost.", { color: C.RED_SUB, size: 18 }],
      ]),
    ])
  );
  children.push(
    ...C.kvTable([
      { label: 'Material / Stone Type *', value: g(sp, 'material_stone_type', 'TBC') },
      { label: 'Grade *', value: `${g(sp, 'grade')} only — no mixed grades, no seconds` },
      { label: 'Surface Finish *', value: g(sp, 'surface_finish', 'TBC') },
      { label: 'Dimensions *', value: g(sp, 'dimensions', 'TBC') },
      { label: 'Dimensional Tolerance', value: g(sp, 'dimensional_tolerance', '±2 mm on L and W · ±0.5 mm on thickness') },
      { label: 'Quantity *', value: g(sp, 'quantity') },
      { label: 'Unit *', value: g(sp, 'unit', 'TBC') },
      { label: 'Colour Reference', value: g(sp, 'colour_reference') },
      { label: 'Special Requirements', value: g(sp, 'special_requirements') },
    ])
  );

  children.push(...C.sectionTitle('4. COMMERCIAL TERMS', green));
  const commercialRows = [
    ['Unit Price (INR) *', g(sp, 'unit_price_inr'), 'Rate as agreed'],
    ['Quantity *', `${g(sp, 'quantity')} ${g(sp, 'unit', '')}`, 'Must match Section 3 exactly'],
    ['Basic Value *', g(sp, 'basic_value_inr'), 'Unit Price × Quantity'],
    ['GST *', `${g(sp, 'gst_rate_pct', 'X')}% — ${g(sp, 'gst_amount_inr')}`, 'CGST + SGST (intrastate) OR IGST (interstate)'],
    ['TOTAL PAYABLE *', g(sp, 'total_payable_inr'), 'Basic Value + GST', true],
    [`Advance (${g(sp, 'advance_pct', 'X')}%) *`, g(sp, 'advance_amount_inr'), 'Payable before production commences'],
    ['Balance *', g(sp, 'balance_amount_inr'), 'Payable ONLY after delivery + inspection + written acceptance by NexaCrest', true],
  ];
  children.push(...C.threeColFinanceTable('Field', commercialRows, green));

  children.push(...C.sectionTitle('5. DELIVERY TERMS', green));
  children.push(
    ...C.kvTable([
      { label: 'Delivery Location *', value: g(sp, 'delivery_location', 'TBC') },
      { label: 'Required Delivery Date *', value: g(sp, 'required_delivery_date', 'TBC') },
      { label: 'Delivery Confirmation', value: 'Supplier must confirm delivery readiness in writing (WhatsApp acceptable) at least 7 days before the required delivery date.' },
      {
        label: 'Time is of the Essence',
        value:
          'Delivery by the agreed date is of the essence of this Purchase Order. Failure to deliver by the agreed date may result in cancellation of this PO and / or recovery of losses incurred by NexaCrest as a result of the delay, including but not limited to demurrage, vessel rebooking charges and buyer penalties.',
      },
      { label: 'Packing *', value: g(sp, 'packing_requirement') },
    ])
  );

  if ((context.terms || []).length) {
    children.push(...C.sectionTitle('6. QUALITY & INSPECTION', green));
    children.push(...C.bulletList(context.terms));
  }

  children.push(...C.sectionTitle('7. SUPPLIER ACCEPTANCE', green));
  const headerRow = new TableRow({
    children: [
      C.cell(C.plain('Issued by NexaCrest International Private Limited', { bold: true, color: C.WHITE }), { width: C.pctWidth(50), shading: C.shade(green) }),
      C.cell(C.plain('Accepted by Supplier  •  Sign, Stamp & Return', { bold: true, color: C.WHITE }), { width: C.pctWidth(50), shading: C.shade(green) }),
    ],
  });
  const leftPara = [
    C.plain(String(g(signatory, 'name', '')), { bold: true, color: C.NAVY }),
    C.plain(String(g(signatory, 'designation', ''))),
    C.plain(String(g(company, 'legal_name', '')), { size: 17, color: '555555' }),
  ];
  const rightPara = [
    C.plain('Authorised Signature:', { bold: true }),
    C.plain('_________________________________', { color: '999999' }),
    C.plain('Name: _____________________________'),
    C.plain('Designation: ______________________'),
    C.plain('Date: _____________________________', { bold: true }),
    C.plain('Supplier Stamp: (mandatory)', { bold: true, color: C.RED_TEXT, size: 17 }),
  ];
  const bodyRow = new TableRow({
    children: [
      C.cell(leftPara, { width: C.pctWidth(50), borders: C.thinBorders() }),
      C.cell(rightPara, { width: C.pctWidth(50), shading: C.shade(C.GREEN_BG), borders: C.thinBorders() }),
    ],
  });
  children.push(new Table({ width: C.FULL_WIDTH, margins: C.TABLE_MARGINS, rows: [headerRow, bodyRow] }));
  children.push(C.spacer(120));
  children.push(
    ...C.colorBox(C.GREEN_BG, green, [
      C.rich([
        ['By signing above, the supplier confirms acceptance of all specifications, commercial terms, delivery terms and quality conditions stated in this Purchase Order. ', { color: green }],
        ['Acceptance of advance payment constitutes full acceptance of all terms herein.', { bold: true, color: green }],
      ]),
    ], 0)
  );

  return children;
}

// ==================================================================
// FDN — Freight Debit Note
// ==================================================================
function renderFdn(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const freight = context.freight || {};
  const company = context.company || {};
  const currency = g(order, 'currency_code', '');
  const children = [];

  children.push(...C.header(context, titleFor('FDN'), 'FREIGHT COST RECOVERY'));
  children.push(C.mandatoryNote());
  children.push(
    ...C.metaBar([
      { label: 'DEBIT NOTE NO. *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'DATE *', value: g(meta, 'generated_date', '') },
      { label: 'PI REFERENCE *', value: g(order, 'pi_ref', '—') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );
  children.push(
    ...C.colorBox(C.RED_BG, C.RED_BORDER, [
      C.plain('⏱  PAYMENT REQUIRED BEFORE SHIPMENT', { bold: true, size: 20, color: C.RED_TEXT }, { alignment: AlignmentType.CENTER }),
      C.rich(
        [
          ['Payment of this Freight Debit Note is required within ', { size: 19, color: C.RED_SUB }],
          ['3 working days', { bold: true, size: 19, color: C.RED_TEXT }],
          [' of the date above. NexaCrest will confirm shipment booking and hand over cargo to the shipping line ', { size: 19, color: C.RED_SUB }],
          ['only upon receipt of full freight payment.', { bold: true, size: 19, color: C.RED_TEXT }],
        ],
        { alignment: AlignmentType.CENTER }
      ),
    ])
  );

  children.push(...defaultSection1(context, 'FROM (SELLER / EXPORTER)'));

  children.push(...C.sectionTitle('2. TO (BUYER)'));
  children.push(
    ...C.kvTable([
      { label: 'Company Legal Name *', value: g(buyer, 'company_legal_name') },
      { label: 'Billing Address *', value: g(buyer, 'billing_address') },
      { label: 'Contact Person *', value: g(buyer, 'contact_person', 'TBC') },
      { label: 'Email *', value: g(buyer, 'email', 'TBC') },
    ])
  );

  const productList = (context.products || []).map((p) => `${p.description || ''} (${p.quantity ?? ''} ${p.unit || ''})`).join('; ');
  children.push(...C.sectionTitle('3. ORDER REFERENCE'));
  children.push(
    ...C.kvTable([
      { label: 'Proforma Invoice No. *', value: g(order, 'pi_ref', '—') },
      { label: 'Product(s)', value: productList },
      { label: 'Incoterm *', value: `${g(order, 'incoterm_label')}. Freight recovery applies as agreed under the referenced Proforma Invoice.` },
      { label: 'Port of Loading *', value: g(order, 'port_of_loading') },
      { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
      { label: 'Shipping Line', value: g(freight, 'freight_forwarder_name', 'TBC') },
      { label: 'Cargo Status *', value: g(context, 'packing.packing_date') !== '—' ? `Packed and ready for shipment as of ${g(context, 'packing.packing_date')}` : 'TBC' },
    ])
  );

  children.push(...C.sectionTitle('4. FREIGHT & CHARGES'));
  children.push(
    ...C.productsTable(['Description *', 'Container Type *', `Amount (${currency}) *`, 'Remarks'], [22, 18, 20, 40], [
      { cells: ['Ocean Freight', g(order, 'container_type'), g(freight, 'confirmed_freight_rate', 'TBC'), `Confirmed rate — ${g(order, 'port_of_loading')} to ${g(order, 'port_of_discharge')}`], align: [null, null, 'right', null] },
      { cells: ['Origin Charges (if any)', '', 'NIL', `THC, documentation charges at ${g(order, 'port_of_loading')} — if applicable`], align: [null, null, 'right', null] },
      { cells: ['Insurance', '', g(order, 'incoterm_code') === 'CIF' ? g(freight, 'insurance_amount', 'TBC') : 'NIL', 'CIF only — if FOB/CFR enter NIL'], align: [null, null, 'right', null] },
      { cells: ['GST / IGST (if applicable)', '', g(freight, 'gst_treatment') === 'IGST_18' ? '18% IGST' : 'NIL', 'As advised by CA — NIL if pure cost reimbursement'], align: [null, null, 'right', null] },
    ])
  );
  children.push(...C.totalsTable([{ label: `TOTAL AMOUNT DUE (${currency}) *  (incl. GST if applicable)`, value: g(freight, 'total_freight_and_insurance', 'TBC'), highlight: true }]));

  children.push(...C.sectionTitle('5. PAYMENT INSTRUCTIONS'));
  children.push(
    ...C.colorBox(C.RED_BG, C.RED_BORDER, [
      C.rich([['Payment Due: ', { bold: true }], [`Within 3 working days of this Debit Note date — by ${g(freight, 'payment_due_date', 'TBC')}`, { color: C.RED_SUB }]]),
      C.rich([['Payment Method: ', { bold: true }], ['T/T (Telegraphic Transfer) to the NexaCrest bank account below', { color: C.RED_SUB }]]),
      C.rich([['Reference: ', { bold: true }], [`Quote Debit Note No. ${g(meta, 'document_reference')} and PI No. ${g(order, 'pi_ref', '—')} in the remittance`, { color: C.RED_SUB }]]),
      C.rich([
        ['⚠ Critical: ', { bold: true }],
        ['Share bank remittance copy as per Section 1 contact details above immediately after payment. Shipment booking will be confirmed only upon verification of freight receipt.', { color: C.RED_SUB }],
      ]),
    ])
  );

  children.push(...C.sectionTitle('6. BANK DETAILS  (for freight T/T payment)'));
  children.push(
    ...C.kvTable(
      bankDetailsRows(
        context,
        `Please quote FDN No. ${g(meta, 'document_reference', '')} in your wire transfer remarks.`,
        `${g(company, 'rbi_purpose_code_freight')} — enter in the "Purpose of Remittance" field of your wire transfer form.`
      )
    )
  );

  children.push(...C.signatureBlock(context));
  return children;
}

// ==================================================================
// PL — Packing List
// ==================================================================
function renderPl(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const packing = context.packing || {};
  const crates = context.crates || [];
  const products = context.products || [];
  const children = [];

  children.push(...C.header(context, titleFor('PL'), null));
  children.push(C.mandatoryNote());
  children.push(
    ...C.metaBar([
      { label: 'PL NUMBER *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'DATE *', value: g(meta, 'generated_date', '') },
      { label: 'PI REFERENCE *', value: g(order, 'pi_ref', '—') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );

  children.push(...defaultSection1(context, 'EXPORTER'));

  children.push(...C.sectionTitle('2. CONSIGNEE / BUYER'));
  const leftPara = [
    ['Company Legal Name *', 'company_legal_name'],
    ['Consignee Name *', 'consignee_name'],
    ['Consignee Address *', 'consignee_address'],
    ['VAT / EORI / Tax Reg. No. *', 'vat_eori_tax_no'],
  ].map(([label, key]) => C.rich([[`${label}\n`, { bold: true, color: C.NAVY }], [String(g(buyer, key, 'TBC')), {}]]));
  const rightPara = [
    ['Incoterm *', g(order, 'incoterm_label')],
    ['Port of Loading *', g(order, 'port_of_loading')],
    ['Port of Discharge *', g(order, 'port_of_discharge')],
    ['Country of Final Destination *', g(buyer, 'country_of_destination', 'TBC')],
    ['Notify Party', g(buyer, 'notify_party', 'Same as consignee')],
  ].map(([label, val]) => C.rich([[`${label}\n`, { bold: true, color: C.NAVY }], [String(val), {}]]));
  children.push(
    new Table({
      width: C.FULL_WIDTH,
      margins: C.TABLE_MARGINS,
      rows: [
        new TableRow({
          children: [
            C.cell(leftPara, { width: C.pctWidth(50), borders: C.thinBorders() }),
            C.cell(rightPara, { width: C.pctWidth(50), shading: C.shade(C.GRAY_LIGHT), borders: C.thinBorders() }),
          ],
        }),
      ],
    })
  );
  children.push(C.spacer(120));

  const productList = products.map((p) => `${p.description || ''}${p.finish ? ` — ${p.finish}` : ''}`).join('; ');
  const hsCodeList = products.map((p) => p.hs_code || '').join(', ');
  children.push(...C.sectionTitle('3. PRODUCT SUMMARY'));
  children.push(
    ...C.kvTable([
      { label: 'Product Description *', value: productList },
      { label: 'HS Code *', value: hsCodeList },
      { label: 'Country of Origin *', value: 'India' },
      { label: 'Total Quantity *', value: g(packing, 'actual_quantity_packed', 'TBC') },
      { label: 'Total No. of Crates *', value: g(packing, 'crate_count', 'TBC') },
      { label: 'Total Net Weight *', value: g(packing, 'total_net_weight_kg') !== '—' ? `${g(packing, 'total_net_weight_kg')} kg` : 'TBC' },
      { label: 'Total Gross Weight *', value: g(packing, 'total_gross_weight_kg') !== '—' ? `${g(packing, 'total_gross_weight_kg')} kg` : 'TBC' },
      { label: 'Total CBM *', value: g(packing, 'total_cbm') !== '—' ? `${g(packing, 'total_cbm')} m³` : 'TBC' },
    ])
  );

  children.push(...C.sectionTitle('4. CRATE-LEVEL BREAKDOWN  (from factory packing data)'));
  children.push(C.plain("Filled from the factory packing supervisor's actual measurements. Each row = one physical crate.", { italics: true, size: 17, color: C.MUTED }));
  children.push(
    C.rich([
      ['Marks & Numbers format: ', { bold: true, color: C.NAVY }],
      [
        `NEXACREST / ${String(g(buyer, 'company_legal_name', '')).toUpperCase()} / ${String(g(order, 'port_of_discharge', '')).toUpperCase()} / C-NNN/TOTAL / ${g(order, 'pi_ref', 'PI NUMBER')} / MADE IN INDIA`,
        { color: C.MUTED },
      ],
    ])
  );

  if (crates.length) {
    const rows = crates.map((cr, i) => {
      const lineColor = i % 2 === 0 ? '1D6FA8' : '2D7D56';
      return {
        cells: [String(cr.crate_no || ''), String(cr.marks_numbers || ''), String(cr.product_description || ''), String(cr.dimensions_lwh_cm || '—')],
        lineColor,
        specText: [
          [`${cr.crate_no || ''}  `, { bold: true, color: lineColor }],
          ['Pcs: ', { bold: true, color: C.NAVY }],
          [`${cr.pcs || '—'}   ·   `, {}],
          ['Net Wt: ', { bold: true, color: C.NAVY }],
          [`${cr.net_weight_kg ? `${cr.net_weight_kg} kg` : '—'}   ·   `, {}],
          ['Gross Wt: ', { bold: true, color: C.NAVY }],
          [`${cr.gross_weight_kg ? `${cr.gross_weight_kg} kg` : '—'}   ·   `, {}],
          ['CBM: ', { bold: true, color: C.NAVY }],
          [`${cr.cbm ? `${cr.cbm} m³` : '—'}   ·   `, {}],
          ['HS Code: ', { bold: true, color: C.NAVY }],
          [String(cr.hs_code || '—'), {}],
        ],
      };
    });
    children.push(...C.productsTable(['Crate No. *', 'Marks & Numbers *', 'Product Description & Finish *', 'Crate Size  L×W×H (cm) *'], [12, 33, 35, 20], rows));
  } else {
    children.push(C.plain('Crate-level breakdown not yet recorded.', { italics: true, size: 17, color: '5b6774' }));
  }

  children.push(...C.sectionTitle('5. DECLARATION'));
  children.push(
    ...C.kvTable([
      {
        full: true,
        value:
          'We hereby declare that the particulars given above are true and correct to the best of our knowledge and belief, and that the goods described herein are of Indian origin.\nFumigation Certificate: A Fumigation Certificate is provided as standard with every NexaCrest shipment and will be included in the final document set couriered to the buyer.',
      },
    ])
  );

  children.push(...C.termsSection(context, context.terms_section_number ?? 9, context.terms_section_title || 'TERMS & CONDITIONS'));
  children.push(...annexureAppendixIfAny(context));
  children.push(...C.signatureBlock(context));
  return children;
}

// ==================================================================
// BLI — Bill of Lading Instruction Sheet
// ==================================================================
function renderBli(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const company = context.company || {};
  const shipping = context.shipping || {};
  const packing = context.packing || {};
  const freight = context.freight || {};
  const bl = context.bl || {};
  const products = context.products || [];
  const currency = g(order, 'currency_code', '');
  const children = [];

  children.push(...C.header(context, titleFor('BLI'), 'SHIPPING INSTRUCTION — FOR CHA / SHIPPING LINE'));
  children.push(
    ...C.colorBox(C.BLUE_BG, '042C53', [
      C.rich([
        ['Send this sheet to your CHA or shipping line BEFORE shipment booking is confirmed. ', { bold: true }],
        ['All details must match the Commercial Invoice and Packing List exactly. Any mismatch on the issued BL is expensive and time-consuming to correct — amendments after BL issuance attract charges and delays.', { italics: true, color: C.BLUE_TEXT }],
      ]),
    ], 0)
  );
  children.push(
    ...C.metaBar([
      { label: 'BL INSTRUCTION NO. *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'DATE *', value: g(meta, 'generated_date', '') },
      { label: 'PI REFERENCE *', value: g(order, 'pi_ref', '—') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );

  children.push(...C.sectionTitle('1. SHIPPER / EXPORTER  (appears on BL exactly as written)'));
  children.push(
    ...C.kvTable(
      [
        { label: 'Company Name', value: String(g(company, 'legal_name', '')).toUpperCase() },
        { label: 'Registered Address', value: g(company, 'registered_office') },
        { label: 'Country', value: 'India' },
        { label: 'GSTIN', value: g(company, 'gstin') },
        { label: 'IEC', value: g(company, 'iec_pan') },
        { label: 'Contact / Phone', value: `${g(company, 'phone')}  |  ${g(company, 'email')}` },
      ],
      true
    )
  );

  children.push(...C.sectionTitle('2. CONSIGNEE  (buyer — appears on BL exactly as written)'));
  children.push(
    ...C.kvTable(
      [
        { label: 'Company Legal Name *', value: g(buyer, 'company_legal_name') },
        { label: 'Full Address *', value: g(buyer, 'consignee_address') !== '—' ? g(buyer, 'consignee_address') : g(buyer, 'billing_address') },
        { label: 'Country *', value: g(buyer, 'country_of_destination', 'TBC') },
        { label: 'VAT / EORI / Tax Ref *', value: g(buyer, 'vat_eori_tax_no', 'TBC') },
      ],
      true
    )
  );

  children.push(...C.sectionTitle('3. NOTIFY PARTY'));
  children.push(...C.kvTable([{ label: 'Same as consignee?', value: g(buyer, 'notify_party', 'SAME AS CONSIGNEE') }], true));

  children.push(...C.sectionTitle('4. SHIPMENT DETAILS'));
  children.push(
    ...C.kvTable(
      [
        { label: 'Port of Loading *', value: g(order, 'port_of_loading') },
        { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
        { label: 'Place of Delivery', value: 'Same as Port of Discharge unless buyer has an inland delivery arrangement' },
        { label: 'Vessel Name *', value: g(shipping, 'vessel_name', 'TBC — to be confirmed by shipping line at time of booking') },
        { label: 'Voyage Number *', value: g(shipping, 'voyage_number', 'TBC') },
        { label: 'ETD (Est. Departure) *', value: g(shipping, 'etd', 'TBC') },
        { label: 'ETA (Est. Arrival) *', value: g(shipping, 'eta', 'TBC') },
      ],
      true
    )
  );

  const containerType = String(g(order, 'container_type', ''));
  children.push(...C.sectionTitle('5. CONTAINER & CARGO DETAILS'));
  children.push(
    ...C.kvTable(
      [
        { label: 'Container Load Type *', value: `${checkbox(true, 'FCL (Full Container Load)')}    ${checkbox(false, 'LCL (Less than Container Load)')}` },
        {
          label: 'Container Size *',
          value: `${checkbox(containerType.includes('20ft'), '20ft Standard')}   ${checkbox(containerType.includes('40ft') && !containerType.includes('High Cube'), '40ft Standard')}   ${checkbox(containerType.includes('High Cube'), '40ft High Cube')}`,
        },
        { label: 'Container No. *', value: g(shipping, 'container_no', 'TBC — to be confirmed by shipping line after stuffing') },
        { label: 'Seal No. *', value: g(shipping, 'seal_no', 'TBC — to be confirmed after stuffing') },
        { label: 'No. of Packages *', value: `${g(packing, 'crate_count') !== '—' ? `${g(packing, 'crate_count')} Wooden Crates` : 'TBC'} — must match Packing List exactly` },
        { label: 'Gross Weight *', value: g(packing, 'total_gross_weight_kg') !== '—' ? `${g(packing, 'total_gross_weight_kg')} kg` : 'TBC' },
        { label: 'Net Weight *', value: g(packing, 'total_net_weight_kg') !== '—' ? `${g(packing, 'total_net_weight_kg')} kg` : 'TBC' },
        { label: 'Total CBM *', value: g(packing, 'total_cbm') !== '—' ? `${g(packing, 'total_cbm')} m³` : 'TBC' },
      ],
      true
    )
  );

  const productList = products.map((p) => `${p.description || ''}${p.finish ? `, ${p.finish}` : ''}`).join('; ');
  const hsCodeList = products.map((p) => p.hs_code || '').join(', ');
  children.push(...C.sectionTitle('6. CARGO DESCRIPTION  (appears on BL exactly as written)'));
  const crateCount = g(context, 'packing.crate_count', 'N');
  children.push(
    ...C.kvTable(
      [
        { label: 'Description of Goods *', value: `${productList} — must match Commercial Invoice exactly` },
        { label: 'HS Code *', value: hsCodeList },
        {
          label: 'Marks & Numbers *',
          value: `NEXACREST / ${String(g(buyer, 'company_legal_name', '')).toUpperCase()} / ${String(g(order, 'port_of_discharge', '')).toUpperCase()} / C-001/${crateCount} to C-${crateCount}/${crateCount} / ${g(order, 'pi_ref', '—')} / MADE IN INDIA`,
        },
        { label: 'Country of Origin *', value: 'India' },
      ],
      true
    )
  );

  const isFob = !!order.is_fob;
  children.push(...C.sectionTitle(`7. FREIGHT & CHARGES ON BL  |  ${g(order, 'incoterm_label')}`));
  children.push(
    ...C.kvTable(
      [
        {
          label: 'Freight Terms *',
          value: `${checkbox(!isFob, 'Freight Prepaid (seller pays freight — CFR/CIF)')}    ${checkbox(isFob, 'Freight Collect (buyer pays freight — FOB)')}`,
        },
        { label: 'Freight Amount', value: isFob ? 'N/A — Freight Collect' : `${g(freight, 'confirmed_freight_rate', 'TBC')} ${currency}` },
      ],
      true
    )
  );

  children.push(
    ...C.colorBox(C.RED_BG, C.RED_BORDER, [
      C.plain('⚠  MANDATORY — DRAFT BL APPROVAL REQUIRED BEFORE ORIGINALS ARE ISSUED', { bold: true, size: 20, color: C.RED_TEXT }),
      C.plain(
        "CHA / Shipping Line must send the complete draft Bill of Lading to NexaCrest for written approval before issuing any original BL. No original BL may be issued without NexaCrest's prior written approval. Send draft BL to NexaCrest at contact details in Section 1 above.",
        { size: 19, color: C.RED_SUB }
      ),
    ])
  );

  children.push(...C.sectionTitle('8. BILL OF LADING TYPE'));
  children.push(
    ...C.colorBox(C.RED_BG, C.RED_BORDER, [
      C.plain('MANDATORY INSTRUCTION — DO NOT ISSUE SEA WAYBILL OR EXPRESS BL', { bold: true, size: 22 }),
      C.rich([['Always issue: ', { bold: true }], ['ORIGINAL NEGOTIABLE BILL OF LADING — 3 ORIGINALS', { bold: true, size: 24 }]]),
      C.rich([
        ['Reason: ', { bold: true, color: C.RED_TEXT }],
        [
          `NexaCrest payment terms are ${g(context.financial || {}, 'advance_pct')}% advance + ${g(context.financial || {}, 'balance_pct')}% balance payable against the original BL. NexaCrest retains all 3 original BLs and only releases them to the buyer AFTER the balance T/T is received and cleared in the NexaCrest bank account. Under a Sea Waybill or Express BL, the buyer can collect the cargo without surrendering any document — NexaCrest would lose all financial leverage and may not receive the balance. A Sea Waybill must NEVER be issued for NexaCrest shipments.`,
          { color: C.RED_SUB },
        ],
      ]),
    ])
  );
  children.push(
    ...C.kvTable(
      [
        { label: 'BL Type *', value: g(bl, 'type_instruction') },
        { label: 'No. of Original Copies *', value: '3 (three originals) — NexaCrest will hold all 3 originals until balance payment is received.' },
        { label: 'No. of Non-Negotiable Copies', value: '3 — for buyer records and NexaCrest file; released freely.' },
        { label: 'BL Consignee Instruction *', value: `${g(bl, 'consignee_instruction')} — ensures the BL is to NexaCrest's order; buyer cannot endorse or use the BL until NexaCrest endorses and releases it.` },
      ],
      true
    )
  );
  children.push(
    ...C.colorBox(C.BLUE_BG, '042C53', [
      C.rich([
        ['Original BL release process: ', { bold: true }],
        [
          'Once all 3 originals are issued, CHA to hand them to NexaCrest only. NexaCrest will courier originals to the buyer after confirming receipt of the balance T/T payment in the NexaCrest bank account. NexaCrest retains all original BLs until balance payment is received and cleared. Cargo release at destination is subject to carrier and destination port procedures.',
          { color: C.BLUE_TEXT },
        ],
      ]),
    ], 0)
  );

  children.push(...C.termsSection(context, context.terms_section_number ?? 9, context.terms_section_title || 'TERMS & CONDITIONS'));
  children.push(...C.signatureBlock(context));
  return children;
}

// ==================================================================
// CI — Commercial Invoice
// ==================================================================
function renderCi(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const financial = context.financial || {};
  const payment = context.payment_status || {};
  const company = context.company || {};
  const shipping = context.shipping || {};
  const packing = context.packing || {};
  const crates = context.crates || [];
  const products = context.products || [];
  const currency = g(order, 'currency_code', '');
  const children = [];

  children.push(...C.header(context, titleFor('CI'), g(order, 'incoterm_code', null) ? `${g(order, 'incoterm_code')} ${String(g(order, 'port_of_loading', '')).toUpperCase()}` : null));
  children.push(C.mandatoryNote());
  children.push(
    ...C.metaBar([
      { label: 'INVOICE NO. *', value: `${g(meta, 'document_reference', '')} ${g(meta, 'revision_label', '')}` },
      { label: 'DATE *', value: g(meta, 'generated_date', '') },
      { label: 'PI REFERENCE *', value: g(order, 'pi_ref', '—') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );
  children.push(
    ...C.colorBox(C.GREEN_BG, C.GREEN_BORDER, [
      C.rich([
        ['GST DECLARATION: ', { bold: true, color: C.GREEN_BORDER, size: 19 }],
        ['Supply meant for export under Letter of Undertaking (LUT) without payment of Integrated Tax (IGST). ', { color: C.GREEN_VALUE, size: 19 }],
        [`LUT Order No.: ${g(company, 'lut_number')}`, { bold: true, color: C.GREEN_BORDER, size: 19 }],
        [`   ·   Valid for ${g(company, 'lut_valid_fy')}   ·   GSTIN: ${g(company, 'gstin')}`, { color: C.GREEN_VALUE, size: 19 }],
      ]),
    ])
  );

  children.push(...defaultSection1(context, 'EXPORTER / SELLER'));

  children.push(...C.sectionTitle('2. BUYER / CONSIGNEE'));
  children.push(
    ...C.kvTable([
      { label: 'Company Legal Name *', value: g(buyer, 'company_legal_name') },
      { label: 'Billing Address *', value: g(buyer, 'billing_address') },
      { label: 'Consignee Name *', value: g(buyer, 'consignee_name') },
      { label: 'Consignee Address *', value: g(buyer, 'consignee_address') },
      { label: 'VAT / EORI / Tax Reg. No. *', value: g(buyer, 'vat_eori_tax_no', 'TBC') },
      { label: 'Contact Person *', value: g(buyer, 'contact_person', 'TBC') },
      { label: 'Email *', value: g(buyer, 'email', 'TBC') },
      { label: 'Notify Party', value: g(buyer, 'notify_party', 'SAME as consignee') },
      { label: 'Country of Final Destination *', value: g(buyer, 'country_of_destination', 'TBC') },
    ])
  );

  children.push(...C.sectionTitle('3. SHIPPING DETAILS'));
  children.push(
    ...C.kvTable([
      { label: 'Incoterm *', value: g(order, 'incoterm_label') },
      { label: 'Port of Loading *', value: g(order, 'port_of_loading') },
      { label: 'Port of Discharge *', value: g(order, 'port_of_discharge') },
      { label: 'Container No. *', value: g(shipping, 'container_no', 'TBC') },
      { label: 'Bill of Lading No. *', value: g(shipping, 'bl_number', 'TBC') },
      { label: 'Bill of Lading Date *', value: g(shipping, 'bl_date', 'TBC — must match this invoice date') },
      { label: 'Vessel / Voyage *', value: `${g(shipping, 'vessel_name', 'TBC')}${g(shipping, 'voyage_number') !== '—' ? ` / ${g(shipping, 'voyage_number')}` : ''}` },
      { label: 'No. of Packages *', value: `${g(packing, 'crate_count') !== '—' ? `${g(packing, 'crate_count')} Wooden Crates` : 'TBC'} — must match Packing List` },
    ])
  );

  children.push(...C.sectionTitle('4. PRODUCT / ORDER DETAILS'));
  children.push(C.plain('Quantities and values below reflect ACTUAL shipment — must match Packing List. Must not exceed PI quantities.', { italics: true, size: 17, color: C.MUTED }));
  children.push(...C.productsTable(productTableHeaders(currency), [6, 34, 10, 10, 20, 20], buildProductRows(products)));
  let plRefLine = `PL Reference: ${g(order, 'pl_ref', 'TBC')}`;
  if (crates.length) {
    plRefLine += crates.length > 1 ? ` — Crates ${crates[0].crate_no || ''} to ${crates[crates.length - 1].crate_no || ''}` : ` — Crate ${crates[0].crate_no || ''}`;
  }
  children.push(C.plain(`${plRefLine}.`, { size: 17, color: C.MUTED }));

  children.push(...C.sectionTitle('5. INVOICE VALUE & PAYMENT SETTLEMENT'));
  children.push(
    ...C.colorBox('E8F1FB', C.BLUE_TEXT, [
      C.plain('FOB Value is the basis for all payments and required for Indian Shipping Bill & IGST refund. Freight & Insurance (if applicable) are recovered separately via Freight Debit Note and are not included in this Commercial Invoice value.', { color: C.BLUE_TEXT }),
    ], 0)
  );

  const isFob = !!order.is_fob;
  const freightRemark = isFob ? 'FOB: buyer arranges — write NIL' : `Paid separately via Freight Debit Note No. ${g(order, 'fdn_ref', 'TBC')} dated ${g(order, 'fdn_date', 'TBC')}. Not included in CI value.`;
  const sectionARows = [
    ['FOB Value (goods)', `${currency} ${g(financial, 'fob_value')} *`, 'Sum of all product lines above — basis for all payments'],
    ['Freight & Insurance', `${isFob ? 'NIL — FOB order' : 'Paid via FDN'} *`, freightRemark],
    ['TOTAL COMMERCIAL INVOICE VALUE', `${currency} ${g(financial, 'fob_value')} *`, `Currency: ${currency}`],
  ];
  children.push(...C.threeColFinanceTable('SECTION A — INVOICE VALUE', sectionARows, C.NAVY_MID));

  const advanceAmt = g(payment, 'advance_amount') !== '—' ? g(payment, 'advance_amount') : g(financial, 'advance_amount');
  const balanceAmt = g(payment, 'balance_amount') !== '—' ? g(payment, 'balance_amount') : g(financial, 'balance_amount');
  const freightStatus = isFob ? 'NIL (FOB)' : g(payment, 'freight_cleared_at') !== '—' ? 'PAID VIA FDN' : 'PENDING';
  const freightAmtCell = isFob ? 'NIL — FOB' : `${currency} ${g(payment, 'freight_amount', 'TBC')} *`;
  const freightRemark2 = isFob ? 'FOB: buyer arranges — write NIL' : `CFR/CIF: Against FDN No. ${g(order, 'fdn_ref', 'TBC')} dated ${g(order, 'fdn_date', 'TBC')}`;
  const freightAmtVal = g(payment, 'freight_amount', null);
  const subtotalPaid = `${currency} ${advanceAmt}${!isFob && freightAmtVal !== '—' && freightAmtVal !== 'TBC' ? ` + ${g(payment, 'freight_amount')} (freight)` : ''}`;
  const sectionBRows = [
    [
      `✓  ${g(financial, 'advance_pct')}% Advance — ${g(payment, 'advance_cleared_at') !== '—' ? 'RECEIVED' : 'PENDING'}`,
      `${currency} ${advanceAmt} *`,
      `Received: ${g(payment, 'advance_cleared_at', 'TBC')}\nAgainst: PI No. ${g(order, 'pi_ref', 'TBC')}`,
    ],
    [`✓  Freight & Insurance — ${freightStatus}`, freightAmtCell, freightRemark2],
    ['SUBTOTAL ALREADY PAID', subtotalPaid, `${g(financial, 'advance_pct')}% Advance${!isFob ? ' + FDN (if applicable)' : ''}`],
    [
      '⇒  BALANCE DUE NOW',
      `${currency} ${balanceAmt} *`,
      `= FOB Value minus ${g(financial, 'advance_pct')}% advance received\nPayable by T/T within ${g(financial, 'balance_days')} days of BL date\nAgainst scanned copy of Bill of Lading\nPayment Reference: Quote CI No. ${g(meta, 'document_reference')}`,
      true,
    ],
    [
      'VERIFICATION',
      `Advance + Balance = FOB Value\n${advanceAmt} + ${balanceAmt} = ${g(financial, 'fob_value')} ✓`,
      'Freight & Insurance paid separately via FDN — not included in CI value or this verification.',
    ],
  ];
  children.push(...C.threeColFinanceTable('SECTION B — PAYMENT SETTLEMENT', sectionBRows, C.NAVY_MID));

  children.push(
    C.rich([
      [`Total Invoice Value in Words (${currency}): `, { bold: true }],
      [g(financial, 'fob_value_in_words', '_________________________________________________________'), { italics: true, color: C.MUTED }],
      [' *', { bold: true, color: 'C0392B' }],
    ])
  );

  children.push(...C.sectionTitle('6. BANK DETAILS  (for balance T/T payment)'));
  children.push(
    ...C.kvTable(
      bankDetailsRows(
        context,
        `Please quote CI No. ${g(meta, 'document_reference', '')} in your wire transfer remarks.`,
        `${g(company, 'rbi_purpose_code_balance')} — enter in the "Purpose of Remittance" field of your wire transfer form.`
      )
    )
  );

  children.push(...C.sectionTitle('7. DOCUMENTS PROVIDED WITH THIS SHIPMENT'));
  children.push(
    ...documentsProvidedParagraphs('The following documents are provided with this shipment:', [
      '1.  Commercial Invoice (this document — signed and stamped)',
      '2.  Packing List (signed and stamped)',
      `3.  Certificate of Origin — ${g(order, 'coo_type')} — Issued by CAPEXIL`,
      '4.  Bill of Lading — Original Negotiable, 3 originals — couriered to buyer after balance T/T is received and cleared',
      '5.  Fumigation Certificate — provided as standard with every shipment',
    ])
  );

  children.push(...C.sectionTitle('8. DECLARATION'));
  children.push(C.plain('We hereby declare that the goods described in this Commercial Invoice are of Indian origin and that the particulars given are true and correct.', { italics: true, color: C.MUTED }));
  children.push(C.plain(`This invoice is issued under Letter of Undertaking (LUT Order No. ${g(company, 'lut_number')}) for export of goods without payment of IGST under the provisions of the IGST Act, 2017.`, { italics: true, color: C.MUTED }));

  children.push(...C.termsSection(context, context.terms_section_number ?? 9, context.terms_section_title || 'TERMS & CONDITIONS'));
  children.push(...annexureAppendixIfAny(context));
  children.push(...C.signatureBlock(context));
  return children;
}

// ==================================================================
// COOPREP — Certificate of Origin Preparation Sheet (internal only)
// ==================================================================
function renderCooprep(context) {
  const order = context.order || {};
  const meta = context.meta || {};
  const buyer = context.buyer || {};
  const company = context.company || {};
  const shipping = context.shipping || {};
  const packing = context.packing || {};
  const financial = context.financial || {};
  const products = context.products || [];
  const children = [];

  children.push(...C.header(context, titleFor('COOPREP'), null));
  children.push(C.mandatoryNote());
  children.push(
    ...C.metaBar([
      { label: 'SHIPMENT REF *', value: g(order, 'pi_ref', '—') },
      { label: 'DATE PREPARED *', value: g(meta, 'generated_date', '') },
      { label: 'COO TYPE *', value: g(order, 'coo_type') },
      { label: 'BUYER INQUIRY REF *', value: g(order, 'buyer_inquiry_ref', '') },
    ])
  );
  children.push(
    ...C.colorBox(C.BLUE_BG, 'B9CADA', [
      C.rich([
        ['Internal use only — not sent to buyer. ', { bold: true, color: C.NAVY }],
        ['Complete this sheet before requesting COO from CAPEXIL. Hand to your CHA along with the documents listed below. COO is issued by CAPEXIL — not by NexaCrest.', { italics: true, color: C.NAVY }],
      ]),
    ], 3)
  );

  children.push(...C.sectionTitle('1. DOCUMENTS TO SUBMIT TO CAPEXIL / CHA'));
  children.push(C.plain('Tick each item once collected and ready. Do not apply for COO until all mandatory items are ticked.', { italics: true, size: 17, color: C.MUTED }));
  const docItems = [
    ['Commercial Invoice (signed copy)', 'Must be signed and stamped. Values, HS code, buyer/seller details must be final — no estimates.', g(order, 'ci_ref', 'NexaCrest_05_CommercialInvoice.docx')],
    ['Packing List (signed copy)', 'Crate count, weights, CBM must be actuals — not estimates from PI.', 'NexaCrest_04_PackingList.docx'],
    ['Fumigation Certificate (copy)', 'Provided as standard with every NexaCrest shipment — collect from fumigation agency and include with COO application.', 'Fumigation agency — standard every shipment'],
    ['Shipping Bill (copy)', 'Filed by CHA with Indian customs. Do not apply for COO before Shipping Bill is filed.', 'CHA provides after filing'],
    ['Bill of Lading (copy)', 'BL number, date, vessel name, port of loading/discharge must match CI and PL exactly.', 'Shipping line / CHA provides'],
    ['RCMC Certificate (copy)', 'NexaCrest RCMC issued by CAPEXIL — already obtained. Check validity date has not expired.', 'Already obtained — check validity'],
    ['IEC Certificate (copy)', `NexaCrest IEC — ${g(company, 'iec_pan')} — already obtained.`, 'Already obtained'],
    ['GSP Declaration (if GSP Form A)', 'Self-declaration of origin criteria — required for GSP Form A only. CHA will advise the format.', 'Required for GSP Form A only'],
  ];
  children.push(
    ...C.checklistTable(
      ['✓', 'Document', 'What to check before submitting', 'Where it comes from'],
      [6, 24, 45, 25],
      docItems.map((r) => ({ cells: ['[ ]', C.plain(r[0], { bold: true }), r[1], r[2]] }))
    )
  );

  children.push(...C.sectionTitle('2. INFORMATION REQUIRED ON THE COO APPLICATION'));
  children.push(C.plain('All values below must match the Commercial Invoice and Packing List exactly. Any mismatch causes rejection.', { italics: true, size: 17, color: C.MUTED }));
  const productList = products.map((p) => `${p.description || ''}${p.finish ? `, ${p.finish}` : ''}`).join('; ');
  const hsCodeList = products.map((p) => p.hs_code || '').join(', ');
  const hardcoded = 'Hardcoded — never changes';
  const infoRows = [
    ['Exporter — Legal Name *', g(company, 'legal_name'), hardcoded],
    ['Exporter — Registered Address *', g(company, 'registered_office'), hardcoded],
    ['Exporter — GSTIN *', g(company, 'gstin'), hardcoded],
    ['Exporter — IEC *', g(company, 'iec_pan'), hardcoded],
    ['Consignee — Legal Name *', g(buyer, 'company_legal_name'), 'Commercial Invoice Section 2'],
    ['Consignee — Address *', g(buyer, 'consignee_address') !== '—' ? g(buyer, 'consignee_address') : g(buyer, 'billing_address'), 'Commercial Invoice Section 2'],
    ['Consignee — Country *', g(buyer, 'country_of_destination', 'TBC'), 'Commercial Invoice Section 2'],
    ['Vessel Name & Voyage No. *', `${g(shipping, 'vessel_name', 'TBC')}${g(shipping, 'voyage_number') !== '—' ? ` / V.${g(shipping, 'voyage_number')}` : ''}`, 'Bill of Lading'],
    ['Port of Loading *', g(order, 'port_of_loading'), hardcoded],
    ['Port of Discharge *', g(order, 'port_of_discharge'), 'Bill of Lading / Commercial Invoice Section 3'],
    ['Bill of Lading No. *', g(shipping, 'bl_number', 'TBC'), 'Bill of Lading'],
    ['Bill of Lading Date *', g(shipping, 'bl_date', 'TBC — same as CI date'), 'Bill of Lading / Commercial Invoice'],
    ['Product Description *', productList, 'Commercial Invoice Section 4'],
    ['HS Code *', hsCodeList, 'Hardcoded — verify against CI'],
    ['Country of Origin *', 'India', hardcoded],
    ['No. of Packages *', g(packing, 'crate_count') !== '—' ? `${g(packing, 'crate_count')} Wooden Crates` : 'TBC', 'Packing List Section 3 / BL'],
    ['Gross Weight *', g(packing, 'total_gross_weight_kg') !== '—' ? `${g(packing, 'total_gross_weight_kg')} kg` : 'TBC', 'Packing List Section 3'],
    ['Net Weight *', g(packing, 'total_net_weight_kg') !== '—' ? `${g(packing, 'total_net_weight_kg')} kg` : 'TBC', 'Packing List Section 3'],
    ['Total CBM *', g(packing, 'total_cbm') !== '—' ? `${g(packing, 'total_cbm')} m³` : 'TBC', 'Packing List Section 3'],
    ['FOB Value *', `${g(order, 'currency_code')} ${g(financial, 'fob_value')}`, 'Commercial Invoice Section 4'],
    ['Invoice No. & Date *', `${g(order, 'ci_ref', 'TBC')} — ${g(order, 'ci_date', 'TBC')}`, 'Commercial Invoice meta bar'],
    ['Competent Authority Signature', 'Signed and stamped by CAPEXIL authorised officer — not by NexaCrest', 'CAPEXIL issues — not your responsibility'],
  ];
  children.push(
    ...C.checklistTable(['Field', 'Value for this shipment *', 'Source document'], [30, 40, 30], infoRows.map((r) => ({ cells: [C.plain(r[0], { bold: true }), r[1], r[2]] })))
  );

  children.push(...C.sectionTitle('3. STEP-BY-STEP PROCESS'));
  const steps = [
    'Confirm COO type with buyer (GSP Form A or non-preferential) — ideally at Quotation/PI stage.',
    'Cargo packed. Packing List finalised with actuals. CHA files Shipping Bill with customs.',
    'Shipment booked. Bill of Lading issued by shipping line.',
    'Complete this preparation sheet — fill Section 2 values from CI, PL, and BL.',
    'Collect all documents listed in Section 1. Tick each checkbox.',
    'Submit complete package to CHA — or apply directly on the CAPEXIL portal (www.capexil.com). CHA handles this in most cases.',
    'CAPEXIL verifies and issues COO — typically 1–3 working days.',
    'COO received. Include with shipment documents sent to buyer (along with CI, PL, BL).',
  ];
  children.push(
    ...C.checklistTable(
      ['Step', 'Instruction'],
      [15, 85],
      steps.map((s, i) => ({ cells: [C.stepBadge(`Step ${i + 1}`), s] }))
    )
  );

  children.push(...C.sectionTitle('4. NEXACREST CAPEXIL REGISTRATION DETAILS'));
  children.push(
    ...C.kvTable([
      { label: 'Prepared By *', value: 'Name of person completing this sheet' },
      { label: 'RCMC Issuing Body', value: 'CAPEXIL — Chemicals and Allied Products Export Promotion Council' },
      { label: 'Member Company', value: g(company, 'legal_name') },
      { label: 'IEC', value: g(company, 'iec_pan') },
      { label: 'RCMC Number *', value: g(company, 'rcmc_number') },
      { label: 'RCMC Valid Until *', value: g(company, 'rcmc_valid_until') },
      { label: 'CAPEXIL Portal', value: 'www.capexil.com — COO applications can be submitted online' },
      { label: 'CAPEXIL Office', value: 'Chennai Regional Office handles granite/stone exporters from Karnataka — confirm with CHA' },
    ])
  );
  children.push(
    ...C.colorBox(C.AMBER_BG2, C.AMBER_BORDER, [
      C.rich([
        ['⚠ Annual renewal: ', { bold: true, color: C.AMBER_TEXT2 }],
        ['RCMC must be renewed annually. If RCMC expires, CAPEXIL cannot issue a COO — your shipment documents will be incomplete and customs clearance will fail. Set a reminder 60 days before expiry.', { color: C.AMBER_TEXT2 }],
      ]),
    ], 4)
  );

  return children;
}

// ------------------------------------------------------------------
// Entry point
// ------------------------------------------------------------------

const RENDERERS = {
  QT: renderQt,
  PI: renderPi,
  OC: renderOc,
  ANNEXA: renderAnnexa,
  BUYERPO: renderBuyerPo,
  SUPPO: renderSupPo,
  FDN: renderFdn,
  PL: renderPl,
  BLI: renderBli,
  CI: renderCi,
  COOPREP: renderCooprep,
};

/** @returns {Promise<Buffer>} */
async function render(documentTypeCode, context) {
  const renderer = RENDERERS[documentTypeCode];
  if (!renderer) {
    throw new Error(`No DOCX render method for document type ${documentTypeCode}`);
  }
  const children = renderer(context);
  const headerObj = C.watermarkHeader(context.watermark || {});

  const doc = new Document({
    styles: C.documentDefaultStyles(),
    sections: [
      {
        properties: { page: C.PAGE_A4 },
        headers: headerObj ? { default: headerObj } : undefined,
        children,
      },
    ],
  });

  return require('docx').Packer.toBuffer(doc);
}

module.exports = { render };
