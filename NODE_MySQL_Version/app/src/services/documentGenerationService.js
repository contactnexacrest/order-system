'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const nunjucks = require('nunjucks');
const { Document, Packer, Paragraph, Table, TableRow, TableCell, TextRun, WidthType, HeadingLevel } = require('docx');

const env = require('../config/env');
const db = require('../config/db');
const amendmentRepository = require('../repositories/amendmentRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const documentRepository = require('../repositories/documentRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const orderRepository = require('../repositories/orderRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const termsClauseRepository = require('../repositories/termsClauseRepository');
const documentDataAssembler = require('./documentDataAssembler');
const { renderPdfFromHtml } = require('./pdfRenderService');

/**
 * Nunjucks -> Puppeteer/Chromium (buyer-facing PDF, always) and, when
 * docx_generation_settings.is_enabled for that document type, also -> the
 * `docx` npm package (internal-only DOCX, content-parity not pixel-parity
 * — the PDF is what carries visual fidelity; matches the PHP build's
 * Twig+Dompdf / Twig+PHPWord split, see README's Phase B scope note).
 */
const TEMPLATES_DIR = path.join(__dirname, '..', '..', 'templates');

let njkEnv = null;
function templatesEnvironment() {
  if (njkEnv) return njkEnv;
  njkEnv = new nunjucks.Environment(new nunjucks.FileSystemLoader(TEMPLATES_DIR, { noCache: true }), {
    autoescape: true,
  });
  // Twig's |date('d F Y') — only ever called with that one format string in
  // these templates, so the format argument is accepted but ignored.
  njkEnv.addFilter('date', (value) => documentDataAssembler.formatDate(value));
  // Nunjucks ships its OWN built-in `nl2br`, but it only copies the input's
  // existing "safe" mark onto its output (nunjucks/src/filters.js) rather
  // than marking freshly-inserted <br /> tags safe itself — since
  // annexure_products text is a plain unmarked string, autoescape then
  // re-escapes those tags into literal "<br />" text on the page. This
  // override matches server.js's app-views environment: escape first
  // (autoescape is on globally, so this filter must do its own escaping
  // since it returns markup), then turn newlines into <br>, then mark safe.
  njkEnv.addFilter('nl2br', (value) => {
    const escaped = nunjucks.runtime.suppressValue(value == null ? '' : String(value), true);
    return new nunjucks.runtime.SafeString(escaped.replace(/\r\n|\r|\n/g, '<br>\n'));
  });
  // Twig's |split(delimiter, limit) — used by BUYERPO to pull the bold
  // "LABEL:" lead-in off an already-fully-substituted T&C clause string
  // (see resolveTerms()'s output shape) for its own bold-label rendering.
  njkEnv.addFilter('split', (value, delimiter, limit) => {
    const parts = String(value == null ? '' : value).split(delimiter);
    if (limit && parts.length > limit) {
      return parts.slice(0, limit - 1).concat(parts.slice(limit - 1).join(delimiter));
    }
    return parts;
  });
  return njkEnv;
}

let fontsBlockCache = null;

/**
 * Document fidelity rebuild (2026-09-21): the real source templates set
 * every run to "Aptos" — Microsoft's current Office default, not freely
 * redistributable and not installed on any VPS this app will ever run on.
 * Carlito (SIL Open Font License, bundled in app/assets/fonts/) is the
 * closest freely redistributable substitute — same humanist-sans category
 * Microsoft's own Calibri/Aptos lineage sits in. Puppeteer/Chromium has no
 * "chroot" restriction the way DOMPDF does (see the PHP stack's
 * registerDocumentFonts() for that story) — a plain @font-face pointing at
 * a data: URI is all it needs, embedded directly in the same self-contained
 * HTML string as the logo/seal images (see documentDataAssembler.assetsBlock()),
 * so page.setContent() still has nothing external to fetch. Read once and
 * cached in memory — these 4 files never change while the process is
 * running, and re-reading + re-base64-encoding ~2.5MB on every single
 * document generation would be pure waste in a long-lived Node process.
 */
function fontsBlock() {
  if (fontsBlockCache) return fontsBlockCache;
  const fontDir = path.join(__dirname, '..', '..', 'assets', 'fonts');
  const toDataUri = (file) => `data:font/ttf;base64,${fs.readFileSync(path.join(fontDir, file)).toString('base64')}`;
  fontsBlockCache = {
    regular_data_uri: toDataUri('Carlito-Regular.ttf'),
    bold_data_uri: toDataUri('Carlito-Bold.ttf'),
    italic_data_uri: toDataUri('Carlito-Italic.ttf'),
    bolditalic_data_uri: toDataUri('Carlito-BoldItalic.ttf'),
  };
  return fontsBlockCache;
}

async function generate(orderId, documentTypeCode, generatedByUserId, signatoryOverrideUserId = null) {
  const docType = await findDocumentType(documentTypeCode);
  if (!docType) {
    throw new Error(`Unknown document type: ${documentTypeCode}`);
  }

  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }

  // Real bug this fixes: orderRepository.setPiDates() existed but was never
  // called anywhere — every PI ever generated showed "VALID UNTIL * TBC" on
  // the document itself, and the PI-send email template's {pi_valid_until}
  // placeholder (docs/seed.sql) would render blank for every buyer.
  // pi_date/pi_valid_until mirror quotation_date/quotation_valid_until (set
  // at order creation) but can only be set here, at actual PI-generation
  // time — set (or reset, on a re-issued PI) every time a PI is generated
  // so the validity window always reflects the most recent issue.
  if (documentTypeCode === 'PI') {
    const piValidityDays = parseInt((await companySettingsRepository.get('pi_validity_days')) || '15', 10);
    const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const today = new Date();
    const validUntil = new Date(today);
    validUntil.setDate(validUntil.getDate() + piValidityDays);
    await orderRepository.setPiDates(orderId, fmt(today), fmt(validUntil));
  }

  const data = await documentDataAssembler.assemble(orderId);

  const existing = await documentRepository.findLatestForOrderAndType(orderId, docType.id);
  const revisionNumber = existing ? parseInt(existing.revision_number, 10) + 1 : 0;

  const documentReference =
    (existing && existing.document_reference) ||
    (await preAssignedReferenceFor(documentTypeCode, orderId)) ||
    (await require('./referenceNumberService').generateDocumentRef(docType.id));

  const watermark = await draftWatermark();
  const terms = await resolveTerms(documentTypeCode, data);
  const signatory = await documentDataAssembler.signatoryBlock(docType.id, signatoryOverrideUserId);

  const context = {
    fonts: fontsBlock(),
    ...data,
    meta: {
      document_reference: documentReference,
      revision_number: revisionNumber,
      revision_label: `Rev.${String(revisionNumber).padStart(2, '0')}`,
      generated_date: formatNow(),
    },
    watermark,
    doc_title: titleFor(documentTypeCode),
    section1_title: section1TitleFor(documentTypeCode),
    terms,
    terms_section_number: termsSectionNumberFor(documentTypeCode),
    terms_section_title: termsSectionTitleFor(documentTypeCode),
    signatory,
  };

  const twig = templatesEnvironment();
  const templateFile = templateFileFor(documentTypeCode);
  const html = twig.render(templateFile, context);

  const storageBase = (env.get('STORAGE_BASE_PATH', '') || '').replace(/\/+$/, '');
  const clientNumber = sanitizePathSegment(order.client_unique_number);
  const orderRef = sanitizePathSegment(order.order_reference);
  const stageSlug = sanitizePathSegment(order.current_stage_slug || 'stage');
  const targetDir = path.join(storageBase, 'clients', clientNumber, orderRef, stageSlug, 'generated');
  fs.mkdirSync(targetDir, { recursive: true });

  // documentReference itself keeps its real "/" separators (that's the
  // correct on-document display format, e.g. SC/QT/2026/1809001) — but a
  // filename can't contain "/", so the download's displayed filename uses
  // a dash-joined version instead of the raw reference.
  const filenameSafeReference = documentReference !== null ? documentReference.replace(/\//g, '-') : documentTypeCode;

  // --- PDF (always) ---
  const pdfBytes = await renderPdfFromHtml(html);
  const pdfUuidName = uuidFilename('pdf');
  const pdfPath = path.join(targetDir, pdfUuidName);
  fs.writeFileSync(pdfPath, pdfBytes);
  const pdfFileId = await fileStoreRepository.insertGenerated(
    null,
    orderId,
    null,
    pdfPath,
    pdfUuidName,
    `${documentTypeCode} ${filenameSafeReference} Rev.${revisionNumber}.pdf`,
    pdfBytes.length,
    'application/pdf',
    generatedByUserId,
    false
  );

  // --- DOCX (only if enabled for this document type) ---
  let docxFileId = null;
  if (await docxEnabledFor(docType.id)) {
    const docxUuidName = uuidFilename('docx');
    const docxPath = path.join(targetDir, docxUuidName);
    await renderDocx(docxPath, documentTypeCode, context);
    const docxStat = fs.statSync(docxPath);
    docxFileId = await fileStoreRepository.insertGenerated(
      null,
      orderId,
      null,
      docxPath,
      docxUuidName,
      `${documentTypeCode} ${filenameSafeReference} Rev.${revisionNumber} (internal).docx`,
      docxStat.size,
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      generatedByUserId,
      true // internal_only — never emailed to buyer, per ARCHITECTURE.md diagram 2
    );
  }

  const documentId = await documentRepository.create(
    orderId,
    docType.id,
    documentReference,
    revisionNumber,
    pdfFileId,
    docxFileId,
    generatedByUserId,
    signatory,
    data.company
  );

  return {
    document_id: documentId,
    document_reference: documentReference,
    revision_number: revisionNumber,
    pdf_file_id: pdfFileId,
    docx_file_id: docxFileId,
  };
}

/**
 * SUPPO is the one document type whose reference has to exist before this
 * function ever runs: order_supplier_po.supplier_po_reference is NOT NULL
 * and is entered (via referenceNumberService, same generator this module
 * would otherwise call) at the point NexaCrest fills the Supplier PO's
 * material/commercial terms — the render needs that data to already be
 * there (documentDataAssembler's supplierPoBlock pulls it from
 * order_supplier_po), so that row is created first. On the first-ever
 * generate() call for a SUPPO (no `documents` row yet, so `existing` is
 * null above), reuse that pre-assigned reference instead of minting a
 * second, different one from the same day's sequence.
 */
async function preAssignedReferenceFor(code, orderId) {
  if (code !== 'SUPPO') {
    return null;
  }
  const row = await orderSupplierPoRepository.findLatestForOrder(orderId);
  return row ? row.supplier_po_reference : null;
}

async function findDocumentType(code) {
  return db.queryOne('SELECT * FROM document_types WHERE code = :code', { code });
}

/**
 * Where a document type sits in the order lifecycle (stages_master
 * sequence) — used only to detect a stage-regeneration cascade risk,
 * never for anything that affects rendering itself. Two types can
 * legitimately share a stage (ANNEXA rides with QT; PL and BLI both
 * belong to the packing/BL stage) since both are produced from the same
 * stage's data and neither is "downstream" of the other. AMD and COOPREP
 * aren't part of the buyer-facing document sequence a regeneration would
 * meaningfully cascade into, so they're excluded.
 */
function stageSequenceFor(code) {
  switch (code) {
    case 'QT':
    case 'ANNEXA':
      return 1;
    case 'BUYERPO':
      return 2;
    case 'PI':
      return 3;
    case 'OC':
      return 4;
    case 'SUPPO':
      return 5;
    case 'FDN':
      return 6;
    case 'PL':
    case 'BLI':
      return 7;
    case 'CI':
      return 8;
    default:
      return null;
  }
}

/**
 * Real risk this guards against: every document type's data comes from a
 * live read of the order at the moment generate() runs (see assemble()
 * above) — there is no propagation from an earlier-stage document to a
 * later one beyond a cross-reference number (PI cites the QT ref, OC
 * cites the PI ref). So if QT is REGENERATED (a new revision of a
 * document type that already existed) after OC has already been
 * generated, OC's already-rendered PDF still reflects whatever data was
 * live when OC was made — it does not silently pick up whatever changed
 * about QT. Nothing technically breaks, but a later-stage document can
 * now be quietly out of step with an earlier-stage one a reviewer or the
 * buyer might compare it against. Called only for a REGENERATION
 * (revisionNumber > 0) — the first-ever generation of a type is normal
 * forward progress, not a cascade risk.
 *
 * @returns {Promise<string[]>} document type codes with an existing
 *          generated document at a later stage than regeneratedCode
 */
async function downstreamDocumentsAtRisk(orderId, regeneratedCode) {
  const sequence = stageSequenceFor(regeneratedCode);
  if (sequence === null) {
    return [];
  }

  const documents = await documentRepository.forOrder(orderId);
  const seen = new Set();
  const atRisk = [];
  for (const doc of documents) {
    const code = doc.document_type_code;
    if (seen.has(code)) {
      continue;
    }
    const docSequence = stageSequenceFor(code);
    if (docSequence !== null && docSequence > sequence) {
      seen.add(code);
      atRisk.push(code);
    }
  }
  return atRisk;
}

/**
 * Public lookup for callers outside this service that need a
 * document_types.id before generate() itself runs — currently just
 * ordersController's saveSupplierPo, which must mint SUPPO's reference
 * number up front (see preAssignedReferenceFor() above).
 */
async function documentTypeIdFor(code) {
  const docType = await findDocumentType(code);
  return docType ? docType.id : null;
}

async function docxEnabledFor(documentTypeId) {
  const row = await db.queryOne('SELECT is_enabled FROM docx_generation_settings WHERE document_type_id = :id', { id: documentTypeId });
  return row ? !!row.is_enabled : false;
}

async function draftWatermark() {
  const row = await db.queryOne("SELECT * FROM watermark_settings WHERE scope = 'global' AND is_draft_mode = 1 LIMIT 1");
  if (!row) return { enabled: false };
  return {
    enabled: true,
    text: row.text_content,
    color: row.color,
    opacity: row.opacity,
    angle: row.angle,
    font_size: row.font_size,
  };
}

/**
 * The watermark a document switches to once reviewWorkflowService considers
 * it fully approved (finalizeApproval() below) — still a visible watermark
 * (Business Rule #8: "No clean PDF exists in this system"), just no longer
 * the amber DRAFT one.
 */
async function finalWatermark() {
  const row = await db.queryOne("SELECT * FROM watermark_settings WHERE scope = 'global' AND is_draft_mode = 0 LIMIT 1");
  if (!row) return { enabled: false };
  return {
    enabled: true,
    text: row.text_content,
    color: row.color,
    opacity: row.opacity,
    angle: row.angle,
    font_size: row.font_size,
  };
}

/**
 * Spec Section 9 — once every required reviewer has approved a document
 * (reviewWorkflowService::approve() decides when that's true), the DRAFT
 * watermark is replaced by the final one. Re-renders the exact same
 * revision (same document_reference, same revision_number) from the
 * order's current data — safe because nothing legitimately changes an
 * order's data between "document generated" and "all reviewers approved"
 * (locked fields, no new stage progression until this document is
 * actually released) — and writes the result as a NEW file_store row
 * rather than overwriting the draft PDF on disk, so the draft copy stays
 * on record for audit purposes (file_store rows are soft-delete-only,
 * never actually removed).
 */
async function finalizeApproval(documentId) {
  const document = await documentRepository.find(documentId);
  if (!document) {
    throw new Error(`Document ${documentId} not found`);
  }
  if (document.order_id === null) {
    // Internal, order-less documents (SOPs, StageGate, WallRef) never go
    // through the buyer-facing review/approval pipeline.
    throw new Error('This document type is not order-scoped and cannot be finalized here.');
  }

  const orderId = document.order_id;
  const order = await orderRepository.find(orderId);
  const documentTypeCode = document.document_type_code;

  const data = await documentDataAssembler.assemble(orderId);
  const terms = await resolveTerms(documentTypeCode, data);

  const context = {
    fonts: fontsBlock(),
    ...data,
    meta: {
      document_reference: document.document_reference,
      revision_number: document.revision_number,
      revision_label: `Rev.${String(document.revision_number).padStart(2, '0')}`,
      generated_date: documentDataAssembler.formatDate(document.generated_at),
    },
    watermark: await finalWatermark(),
    doc_title: titleFor(documentTypeCode),
    section1_title: section1TitleFor(documentTypeCode),
    terms,
    terms_section_number: termsSectionNumberFor(documentTypeCode),
    terms_section_title: termsSectionTitleFor(documentTypeCode),
    // Re-rendering the SAME document (draft -> final watermark swap) must
    // keep showing the same signatory it was originally generated with —
    // read from the row's own snapshot, never re-resolved from current
    // defaults (see PHP-side "Real bugs found" note: without this, the
    // buyer-facing FINAL PDF rendered with a blank signature/seal block,
    // since this render path never included a `signatory` key at all).
    signatory: await documentDataAssembler.signatoryFromSnapshot(document),
    // Same reasoning, extended to company/bank/LUT details: `data` above
    // re-read company_settings LIVE via assemble() -> companyBlock(), so a
    // bank account switch or LUT renewal made during the review window
    // would have silently changed what the buyer-facing FINAL PDF shows
    // versus the DRAFT a reviewer actually approved. Override with the
    // row's own snapshot.
    company: await documentDataAssembler.companyFromSnapshot(document),
  };

  const twig = templatesEnvironment();
  const html = twig.render(templateFileFor(documentTypeCode), context);
  const pdfBytes = await renderPdfFromHtml(html);

  const storageBase = (env.get('STORAGE_BASE_PATH', '') || '').replace(/\/+$/, '');
  const clientNumber = sanitizePathSegment(order.client_unique_number);
  const orderRef = sanitizePathSegment(order.order_reference);
  const stageSlug = sanitizePathSegment(order.current_stage_slug || 'stage');
  const targetDir = path.join(storageBase, 'clients', clientNumber, orderRef, stageSlug, 'generated');
  fs.mkdirSync(targetDir, { recursive: true });

  const filenameSafeReference = document.document_reference !== null ? document.document_reference.replace(/\//g, '-') : documentTypeCode;
  const finalUuidName = uuidFilename('pdf');
  const finalPath = path.join(targetDir, finalUuidName);
  fs.writeFileSync(finalPath, pdfBytes);

  const finalPdfFileId = await fileStoreRepository.insertGenerated(
    null,
    orderId,
    null,
    finalPath,
    finalUuidName,
    `${documentTypeCode} ${filenameSafeReference} Rev.${document.revision_number} (approved).pdf`,
    pdfBytes.length,
    'application/pdf',
    null,
    false
  );

  await documentRepository.markApproved(documentId, finalPdfFileId);
}

/**
 * Section 8 — Payment Terms Amendment Agreement. Unlike every other
 * document type, this one's content comes entirely from a single
 * `amendments` row (frozen at request time — original_terms_snapshot —
 * plus the amended terms typed in alongside it), not from
 * documentDataAssembler.assemble()'s live order snapshot: an amendment is
 * a legal record of what changed and when, so it must render the same way
 * even if the order's live data moves on afterwards. Can only be called
 * after MD approval (enforced by the amendments controller, not here) —
 * this function just renders whatever status the amendment is in.
 */
async function generateAmendment(amendmentId, generatedByUserId) {
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    throw new Error(`Amendment ${amendmentId} not found`);
  }
  const orderId = amendment.order_id;
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }

  const docTypeId = await documentTypeIdFor('AMD');
  if (!docTypeId) {
    throw new Error('AMD document type is not seeded.');
  }

  const snapshot = amendment.original_terms_snapshot || {};
  const company = await documentDataAssembler.companyBlock();
  const assets = await documentDataAssembler.assetsBlock();
  const signatory = await documentDataAssembler.signatoryBlock(docTypeId);

  const context = {
    fonts: fontsBlock(),
    company,
    assets,
    signatory,
    order: { incoterm_code: null }, // suppresses the default header incoterm chip — not relevant to a legal amendment record
    doc_title: titleFor('AMD'),
    section1_title: section1TitleFor('AMD'),
    watermark: await draftWatermark(),
    meta: {
      document_reference: amendment.amendment_reference,
      revision_number: 0,
      revision_label: '',
      generated_date: formatNow(),
    },
    amendment: {
      amendment_reference: amendment.amendment_reference,
      buyer_inquiry_ref: amendment.buyer_inquiry_ref,
      effective_from: documentDataAssembler.formatDate(amendment.effective_from),
      reason: amendment.reason,
      requested_by: amendment.requested_by === 'importer' ? 'The Importer' : 'NexaCrest International Private Limited',
      md_approved_at: documentDataAssembler.formatDate(amendment.md_approved_at),
      original: {
        quotation_ref: snapshot.quotation_ref ?? null,
        quotation_date: snapshot.quotation_date ?? null,
        pi_ref: snapshot.pi_ref ?? null,
        pi_date: snapshot.pi_date ?? null,
        oc_ref: snapshot.oc_ref ?? null,
        product_summary: snapshot.product_summary ?? null,
        total_fob_value: snapshot.total_fob_value ?? null,
        advance_terms_text: snapshot.advance_terms_text ?? null,
        advance_amount: snapshot.advance_amount ?? null,
        balance_terms_text: snapshot.balance_terms_text ?? null,
        balance_amount: snapshot.balance_amount ?? null,
        freight_terms: snapshot.freight_terms ?? null,
        currency: snapshot.currency ?? null,
        port_of_loading: snapshot.port_of_loading ?? null,
        incoterm_label: snapshot.incoterm_label ?? null,
        lut_number: snapshot.lut_number ?? null,
        gstin: snapshot.gstin ?? null,
        iec_pan: snapshot.iec_pan ?? null,
      },
      amended: {
        // The amendments table stores the amended ADVANCE side as a
        // percentage + amount only (no separate free-text
        // trigger-condition column) — real-world amendments per the
        // spec's own example almost always renegotiate the BALANCE side,
        // not the advance trigger condition, so the advance description
        // below reuses the original PI's trigger wording (frozen in the
        // snapshot) with just the percentage swapped in. Any wording
        // change beyond the percentage belongs in the Reason field, which
        // prints in full on this document.
        advance_pct: amendment.amended_advance_pct,
        advance_terms_text:
          amendment.amended_advance_pct !== null
            ? `${trimTrailingZeros(Number(amendment.amended_advance_pct).toFixed(2))}% advance T/T on FOB Value ${snapshot.advance_trigger_text ?? ''}`
            : null,
        advance_amount: amendment.amended_advance_amount !== null ? documentDataAssembler.formatMoney(amendment.amended_advance_amount) : null,
        balance_terms: amendment.amended_balance_terms,
        balance_amount: amendment.amended_balance_amount !== null ? documentDataAssembler.formatMoney(amendment.amended_balance_amount) : null,
      },
    },
    buyer: {
      company_legal_name: order.company_legal_name,
      billing_address: order.billing_address,
      vat_eori_tax_no: order.vat_eori_tax_no,
      contact_person: order.contact_person,
      phone: order.client_phone,
      email: order.client_email,
    },
  };

  const twig = templatesEnvironment();
  const html = twig.render('AMD/payment_terms_amendment.njk', context);
  const pdfBytes = await renderPdfFromHtml(html);

  const storageBase = (env.get('STORAGE_BASE_PATH', '') || '').replace(/\/+$/, '');
  const clientNumber = sanitizePathSegment(order.client_unique_number);
  const orderRef = sanitizePathSegment(order.order_reference);
  const amdRefSafe = sanitizePathSegment(amendment.amendment_reference);
  const targetDir = path.join(storageBase, 'clients', clientNumber, orderRef, 'amendments', amdRefSafe, 'generated');
  fs.mkdirSync(targetDir, { recursive: true });
  const amdUuidName = uuidFilename('pdf');
  const pdfPath = path.join(targetDir, amdUuidName);
  fs.writeFileSync(pdfPath, pdfBytes);

  const pdfFileId = await fileStoreRepository.insertGenerated(
    null,
    orderId,
    null,
    pdfPath,
    amdUuidName,
    `AMD ${amendment.amendment_reference.replace(/\//g, '-')}.pdf`,
    pdfBytes.length,
    'application/pdf',
    generatedByUserId,
    false
  );

  const documentId = await documentRepository.create(orderId, docTypeId, amendment.amendment_reference, 0, pdfFileId, null, generatedByUserId, signatory, company);

  await amendmentRepository.attachDocument(amendmentId, documentId);

  return { document_id: documentId, pdf_file_id: pdfFileId };
}

function templateFileFor(code) {
  const map = {
    QT: 'QT/quotation.njk',
    ANNEXA: 'ANNEXA/annexure.njk',
    PI: 'PI/proforma_invoice.njk',
    OC: 'OC/order_confirmation.njk',
    BUYERPO: 'BUYERPO/buyer_po.njk',
    SUPPO: 'SUPPO/supplier_po.njk',
    FDN: 'FDN/freight_debit_note.njk',
    PL: 'PL/packing_list.njk',
    BLI: 'BLI/bl_instruction.njk',
    CI: 'CI/commercial_invoice.njk',
    COOPREP: 'COOPREP/coo_prep.njk',
    AMD: 'AMD/payment_terms_amendment.njk',
  };
  const file = map[code];
  if (!file) {
    throw new Error(`No template mapped for document type ${code}`);
  }
  return file;
}

/**
 * Content-parity internal DOCX — a straightforward `docx` package
 * rendering of the same assembled data, not a pixel-for-pixel copy of the
 * PDF layout (see module docblock).
 */
async function renderDocx(targetPath, documentTypeCode, context) {
  const productRows = [
    new TableRow({
      children: ['#', 'Description', 'Qty', 'Unit', 'Unit Price', 'Amount'].map(
        (h) => new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: h, bold: true })] })] })
      ),
    }),
    ...context.products.map(
      (p, i) =>
        new TableRow({
          children: [String(i + 1), p.description, p.quantity, p.unit || '', p.unit_price, p.amount].map(
            (v) => new TableCell({ children: [new Paragraph(String(v ?? ''))] })
          ),
        })
    ),
  ];

  const doc = new Document({
    sections: [
      {
        children: [
          new Paragraph({ children: [new TextRun({ text: context.company.legal_name, bold: true, size: 32 })] }),
          new Paragraph({ children: [new TextRun({ text: titleFor(documentTypeCode), bold: true, size: 26 })] }),
          new Paragraph(''),
          new Paragraph(`${documentTypeCode} No.: ${context.meta.document_reference} ${context.meta.revision_label}`),
          new Paragraph(`Date: ${context.meta.generated_date}`),
          new Paragraph(`Buyer Inquiry Ref: ${context.order.buyer_inquiry_ref}`),
          new Paragraph(''),
          new Paragraph({ children: [new TextRun({ text: `Buyer: ${context.buyer.company_legal_name}`, bold: true })] }),
          new Paragraph(context.buyer.billing_address || ''),
          new Paragraph(''),
          new Table({ width: { size: 100, type: WidthType.PERCENTAGE }, rows: productRows }),
          new Paragraph(''),
          new Paragraph({
            children: [new TextRun({ text: `FOB Value (${context.order.currency_code}): ${context.financial.fob_value}`, bold: true })],
          }),
          new Paragraph(`Advance (${context.financial.advance_pct}%): ${context.financial.advance_amount}`),
          new Paragraph(`Balance (${context.financial.balance_pct}%): ${context.financial.balance_amount}`),
        ],
      },
    ],
  });

  const buffer = await Packer.toBuffer(doc);
  fs.writeFileSync(targetPath, buffer);
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
    AMD: 'PAYMENT TERMS AMENDMENT AGREEMENT',
  };
  return map[code] ?? code;
}

/**
 * Section 1 of every business document is always NexaCrest's own company
 * block (see _layout.njk) — only the heading above it changes, because in
 * a Supplier PO NexaCrest is the "buyer" of raw material, not the
 * "seller", and a Buyer PO calls NexaCrest the "supplier". Wording is
 * taken verbatim from each source template.
 */
function section1TitleFor(code) {
  const map = {
    BUYERPO: 'SUPPLIER',
    SUPPO: 'BUYER (NEXACREST INTERNATIONAL PRIVATE LIMITED)',
    FDN: 'FROM (SELLER / EXPORTER)',
    CI: 'EXPORTER / SELLER',
    BLI: 'SHIPPER / EXPORTER (APPEARS ON BL EXACTLY AS WRITTEN)',
    AMD: 'THE EXPORTER',
    PL: 'EXPORTER',
  };
  return map[code] ?? 'SELLER / EXPORTER'; // QT, PI, OC
}

function termsSectionNumberFor(code) {
  const map = { QT: 7, PI: 8, OC: 7, BUYERPO: 5, SUPPO: 6 }; // SUPPO: "6. QUALITY & INSPECTION" in the source template
  return map[code] ?? 9;
}

function termsSectionTitleFor(code) {
  // The real Order Confirmation template calls this section "ORDER
  // CONDITIONS", and the Supplier PO calls it "QUALITY & INSPECTION" —
  // wording differences preserved from each source document, not an
  // inconsistency. PL/FDN/CI/BLI/COOPREP have no clauses linked to them
  // (see seed.sql), so `terms` comes back empty and _layout.njk's
  // `{% if terms %}` guard skips this section for them entirely rather
  // than showing an empty heading.
  if (code === 'OC') return 'ORDER CONDITIONS';
  if (code === 'SUPPO') return 'QUALITY & INSPECTION';
  return 'TERMS & CONDITIONS';
}

/** @returns {Promise<string[]>} fully-substituted clause text, in order */
async function resolveTerms(documentTypeCode, data) {
  const clauses = await termsClauseRepository.forDocumentTypeCode(documentTypeCode);
  const tolerance = (await companySettingsRepository.get('quantity_shortfall_tolerance_pct')) || '5';
  const quotationValidityDays = (await companySettingsRepository.get('quotation_validity_days')) || '30';
  const piValidityDays = (await companySettingsRepository.get('pi_validity_days')) || '15';

  const replacements = {
    '{tolerance}': trimTrailingZeros(String(tolerance)),
    '{advance_pct}': data.financial.advance_pct,
    '{balance_pct}': data.financial.balance_pct,
    '{quotation_validity_days}': quotationValidityDays,
    '{pi_validity_days}': piValidityDays,
  };

  return clauses.map((c) => {
    let text = c.text;
    for (const [needle, value] of Object.entries(replacements)) {
      text = text.split(needle).join(String(value));
    }
    return text;
  });
}

function sanitizePathSegment(value) {
  return String(value ?? '').replace(/[^A-Za-z0-9_-]+/g, '-') || 'x';
}

/**
 * Spec Section 14 — "PDF filenames = UUIDs — never guessable." The folder
 * path (clients/{client}/{order}/{stage}/generated/) stays human-navigable
 * — that's just organization, never web-reachable since storage/ sits
 * outside the app's static-file root — but the leaf filename itself must
 * not be derivable from the document reference the way
 * `qt_SC-QT-2026-1809001_rev0.pdf` is. fileStoreRepository.find()'s caller
 * only ever reads `server_path` from the DB row (never reconstructs it),
 * and downloads present `original_filename` for Content-Disposition, so
 * this is a pure storage-layer change with no effect on what a user sees
 * or downloads.
 */
function uuidFilename(extension) {
  return `${crypto.randomBytes(16).toString('hex')}.${extension}`;
}

function trimTrailingZeros(numStr) {
  if (!numStr.includes('.')) return numStr;
  return numStr.replace(/0+$/, '').replace(/\.$/, '');
}

function formatNow() {
  const months = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];
  const d = new Date();
  return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
}

module.exports = {
  generate,
  finalizeApproval,
  generateAmendment,
  documentTypeIdFor,
  downstreamDocumentsAtRisk,
  templatesEnvironment,
};
