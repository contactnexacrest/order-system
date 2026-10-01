'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const nunjucks = require('nunjucks');

const env = require('../config/env');
const db = require('../config/db');
const amendmentRepository = require('../repositories/amendmentRepository');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const documentRepository = require('../repositories/documentRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderRepository = require('../repositories/orderRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const orderProfitabilityService = require('./orderProfitabilityService');
const termsClauseRepository = require('../repositories/termsClauseRepository');
const userRepository = require('../repositories/userRepository');
const documentDataAssembler = require('./documentDataAssembler');
const { renderPdfFromHtml } = require('./pdfRenderService');
const docxDocumentBuilder = require('./docx/docxDocumentBuilder');

/**
 * Nunjucks -> Puppeteer/Chromium (buyer-facing PDF, always) and, when
 * docx_generation_settings.is_enabled for that document type, also -> the
 * `docx` npm package (internal-only DOCX, content-parity not pixel-parity
 * — the PDF is what carries visual fidelity; matches the PHP build's
 * Twig+Dompdf / Twig+PHPWord split, see README's Phase B scope note).
 */
const TEMPLATES_DIR = path.join(__dirname, '..', '..', 'templates');

/**
 * Thrown by generate() when an order is locked or the target document
 * type's stage hasn't unlocked yet — a distinct type (rather than a plain
 * Error) so the controller can show its message to the user directly
 * instead of the generic "check the server log" treatment every other
 * generation failure gets. The message itself is always safe to show: it
 * only ever describes order/stage state, never internal detail.
 */
class StageGateBlockedError extends Error {}

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
  // Twig's |format() (sprintf) — only used by CAFIN/ca_financial_annexure.njk
  // so far, and only with the two directives that template needs: %.Nf
  // (fixed-decimal money) and %0Nd (zero-padded revision number).
  njkEnv.addFilter('format', (fmt, ...args) => {
    let i = 0;
    return String(fmt).replace(/%(0?)(\d*)(?:\.(\d+))?([df])/g, (match, zeroFlag, width, precision, type) => {
      const val = args[i++];
      if (type === 'f') {
        const p = precision !== undefined ? parseInt(precision, 10) : 6;
        return Number(val).toFixed(p);
      }
      let s = String(Math.trunc(Number(val)));
      const w = width ? parseInt(width, 10) : 0;
      if (zeroFlag && s.length < w) {
        s = '0'.repeat(w - s.length) + s;
      }
      return s;
    });
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

async function generate(orderId, documentTypeCode, generatedByUserId, signatoryOverrideUserId = null, generateDocx = false, allowOverride = false) {
  const docType = await findDocumentType(documentTypeCode);
  if (!docType) {
    throw new Error(`Unknown document type: ${documentTypeCode}`);
  }

  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }

  // Real, dangerous gap this closes (found 2026-09-25): the order-detail
  // view only ever hides a "Generate"/"Regenerate" button once the order
  // is locked/complete or the relevant stage hasn't unlocked yet —
  // generate() itself never checked either condition, so posting the
  // route directly (or a bug in the view's own condition) could still
  // generate ANY document type at ANY time, including regenerating an
  // early-stage document (QT, PI, ...) after the order has already closed.
  // allowOverride is the one deliberately narrow escape hatch — callers
  // must have already verified the caller holds edit_locked_data (Super
  // Admin bypasses this check automatically — see permissionService.can())
  // and captured a mandatory reason before setting it true; see
  // documentController.js's generate().
  if (!allowOverride) {
    if (order.is_locked) {
      throw new StageGateBlockedError(
        `Order #${orderId} is locked (status: ${order.status}) — documents can no longer be generated or regenerated for it. ` +
        'A user with the "Override locked data" permission can force this through with a reason if absolutely necessary.'
      );
    }

    const requiredStage = stageSequenceFor(documentTypeCode);
    if (requiredStage !== null) {
      const stage = await orderStageRepository.findByOrderAndStageNumber(orderId, requiredStage);
      if (!stage || stage.status === 'locked') {
        throw new StageGateBlockedError(
          `Stage ${requiredStage} isn't open yet for order #${orderId} — ${documentTypeCode} can't be generated out of sequence. ` +
          'A user with the "Override locked data" permission can force this through with a reason if absolutely necessary.'
        );
      }
    }
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

  const data = await documentDataAssembler.assemble(orderId, documentTypeCode);

  const existing = await documentRepository.findLatestForOrderAndType(orderId, docType.id);
  // QA-5 CONC-04: reserved atomically, up front — see
  // referenceNumberService.nextDocumentRevisionNumber()'s docblock for why
  // a plain `existing.revision_number + 1` read (with the actual INSERT
  // not landing until after PDF/DOCX rendering finished) let two
  // concurrent regenerations of the same document silently share one
  // revision number.
  const revisionNumber = await require('./referenceNumberService').nextDocumentRevisionNumber(orderId, docType.id);

  // Client-facing revision (docs/schema.sql Section AH) — how many
  // documents of this (order, type) the client has actually already been
  // sent. Deliberately NOT derived from revisionNumber: staff can
  // regenerate as many times as needed to fix an internal mistake before
  // the first real send, and none of that churn should ever reach the
  // client as a jump from "Rev.00" to "Rev.09".
  const clientRevisionNumber = await documentRepository.countPriorSent(orderId, docType.id);

  const documentReference =
    (existing && existing.document_reference) ||
    (await preAssignedReferenceFor(documentTypeCode, orderId)) ||
    (await require('./referenceNumberService').generateDocumentRef(docType.id));

  const watermark = withRevisionStamp(await draftWatermark(), clientRevisionNumber, docType.category);
  const terms = await resolveTerms(documentTypeCode, data);
  const signatory = await documentDataAssembler.signatoryBlock(docType.id, signatoryOverrideUserId);

  const context = {
    fonts: fontsBlock(),
    ...data,
    meta: {
      document_reference: documentReference,
      revision_number: revisionNumber,
      revision_label: `Rev.${String(revisionNumber).padStart(2, '0')}`,
      client_revision_number: clientRevisionNumber,
      client_revision_label: `Rev.${String(clientRevisionNumber).padStart(2, '0')}`,
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
    `${documentTypeCode} ${filenameSafeReference} Rev.${clientRevisionNumber}.pdf`,
    pdfBytes.length,
    'application/pdf',
    generatedByUserId,
    false
  );

  // --- DOCX (only if the user asked for it AND it's admin-enabled for this document type) ---
  let docxFileId = null;
  if (generateDocx && (await docxEnabledFor(docType.id))) {
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
    data.company,
    clientRevisionNumber
  );

  return {
    document_id: documentId,
    document_reference: documentReference,
    revision_number: revisionNumber,
    client_revision_number: clientRevisionNumber,
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
 * sequence) — used both to detect a stage-regeneration cascade risk
 * (downstreamDocumentsAtRisk() below) AND, since 2026-09-25, as the
 * server-side stage-gate check in generate() itself — the order-detail
 * view already hides a document type's "Generate" button until its stage
 * unlocks, but generate() never verified that server-side, so posting the
 * route directly could generate any document type at any time regardless
 * of stage. Two types can legitimately share a stage (ANNEXA rides with
 * QT; PL and BLI both belong to the packing/BL stage) since both are
 * produced from the same stage's data and neither is "downstream" of the
 * other. AMD isn't part of the buyer-facing document sequence a
 * regeneration would meaningfully cascade into, so it's excluded (it has
 * its own approval workflow instead — see amendmentService.js).
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
    case 'COOPREP':
      return 9;
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
  return watermarkFromRow(row);
}

/**
 * mode ('text'|'image'|'both') decides what the Nunjucks watermark block
 * actually renders (see _layout.njk) — both a text overlay and an image
 * overlay can be present on the same document at once, they're independent
 * layers, not mutually exclusive.
 */
async function watermarkFromRow(row) {
  if (!row) return { enabled: false };
  const mode = row.mode || 'text';
  let imageDataUri = null;
  if ((mode === 'image' || mode === 'both') && row.image_asset_id) {
    const asset = await db.queryOne('SELECT * FROM assets WHERE id = :id', { id: row.image_asset_id });
    imageDataUri = assetDataUri(asset);
  }
  return {
    enabled: true,
    mode,
    show_text: mode === 'text' || mode === 'both',
    show_image: (mode === 'image' || mode === 'both') && imageDataUri !== null,
    text: row.text_content,
    color: row.color,
    opacity: row.opacity,
    angle: row.angle,
    font_size: row.font_size,
    image_data_uri: imageDataUri,
    image_opacity: row.image_opacity != null ? row.image_opacity : 0.15,
    image_position: row.image_position || 'center',
  };
}

function assetDataUri(asset) {
  if (!asset) return null;
  if (!fs.existsSync(asset.server_path)) {
    // Point 8 fix — this used to fail silently: mode 'both' would quietly
    // render only the text layer with no error anywhere, looking exactly
    // like the image branch was never coded. Now it's at least visible in
    // the logs, and watermarkController additionally refuses to save
    // 'image'/'both' with no on-disk file in the first place (see its own
    // comment).
    console.error(`[WATERMARK] Asset id ${asset.id} references missing file: ${asset.server_path}`);
    return null;
  }
  const buf = fs.readFileSync(asset.server_path);
  return `data:${asset.mime_type || 'image/png'};base64,${buf.toString('base64')}`;
}

/**
 * The watermark a document switches to once reviewWorkflowService considers
 * it fully approved (finalizeApproval() below) — still a visible watermark
 * (Business Rule #8: "No clean PDF exists in this system"), just no longer
 * the amber DRAFT one.
 */
async function finalWatermark() {
  const row = await db.queryOne("SELECT * FROM watermark_settings WHERE scope = 'global' AND is_draft_mode = 0 LIMIT 1");
  return watermarkFromRow(row);
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

  const data = await documentDataAssembler.assemble(orderId, documentTypeCode);
  const terms = await resolveTerms(documentTypeCode, data);

  const context = {
    fonts: fontsBlock(),
    ...data,
    meta: {
      document_reference: document.document_reference,
      revision_number: document.revision_number,
      revision_label: `Rev.${String(document.revision_number).padStart(2, '0')}`,
      client_revision_number: document.client_revision_number,
      client_revision_label: `Rev.${String(document.client_revision_number).padStart(2, '0')}`,
      generated_date: documentDataAssembler.formatDate(document.generated_at),
    },
    watermark: withRevisionStamp(await finalWatermark(), document.client_revision_number, document.document_type_category),
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
    `${documentTypeCode} ${filenameSafeReference} Rev.${document.client_revision_number} (approved).pdf`,
    pdfBytes.length,
    'application/pdf',
    null,
    false
  );

  await documentRepository.markApproved(documentId, finalPdfFileId);
}

/**
 * The buyer's own copy must be unmistakable about which revision they're
 * looking at — not just in the on-page revision labels but stamped into
 * the watermark itself, since the watermark is the one thing visible on
 * every page of a printed or forwarded copy. Only applied from the second
 * client-facing issue onward (client_revision_number 0 is the first-ever
 * send — nothing to distinguish it from) and only for customer_facing
 * document types (BLI/COOPREP/SUPPO etc. are never buyer-facing, so a
 * revision count on them would mean nothing to whoever's reading one).
 */
function withRevisionStamp(watermark, clientRevisionNumber, documentTypeCategory) {
  if (!watermark.enabled || !watermark.show_text || !clientRevisionNumber || clientRevisionNumber < 1 || documentTypeCategory !== 'customer_facing') {
    return watermark;
  }
  return {
    ...watermark,
    text: `${String(watermark.text ?? '').trim()} — REVISION ${String(clientRevisionNumber).padStart(2, '0')}`,
  };
}

/**
 * Hardcoded rather than read from watermark_settings — there's no admin
 * need to customize how an INVALID stamp looks, and keeping it out of the
 * DB means it can never accidentally be reconfigured into something less
 * obvious. Deliberately impossible to miss: bright red, large, steep
 * angle — the opposite design intent of the DRAFT/final watermarks, which
 * are meant to be visible but unobtrusive.
 */
function invalidWatermark() {
  return {
    enabled: true,
    mode: 'text',
    show_text: true,
    show_image: false,
    text: 'INVALID DOCUMENT — SUPERSEDED',
    color: '#C0152F',
    opacity: 0.35,
    angle: 35,
    font_size: 54,
    image_data_uri: null,
    image_opacity: 0,
    image_position: 'center',
  };
}

/**
 * A newer revision of this (order, type) has just been fully approved —
 * this older, previously-approved/sent revision is no longer the valid
 * copy. Re-renders it from its own stored snapshot (exact same pattern as
 * finalizeApproval() above: same document_reference, same revision_number,
 * signatory/company read from the row's own snapshot, never live data)
 * with the INVALID DOCUMENT watermark instead of the final one, writes
 * the result as a new file_store row (the previously-approved PDF is left
 * alone on disk/DB, per the soft-delete-only convention), and repoints
 * pdf_file_id to it so anyone who opens/downloads this document from here
 * on sees the invalidated copy, never the old clean one.
 */
async function markSuperseded(documentId) {
  const document = await documentRepository.find(documentId);
  if (!document || document.order_id === null) {
    return;
  }

  const orderId = document.order_id;
  const order = await orderRepository.find(orderId);
  const documentTypeCode = document.document_type_code;

  const data = await documentDataAssembler.assemble(orderId, documentTypeCode);
  const terms = await resolveTerms(documentTypeCode, data);

  const context = {
    fonts: fontsBlock(),
    ...data,
    meta: {
      document_reference: document.document_reference,
      revision_number: document.revision_number,
      revision_label: `Rev.${String(document.revision_number).padStart(2, '0')}`,
      client_revision_number: document.client_revision_number,
      client_revision_label: `Rev.${String(document.client_revision_number).padStart(2, '0')}`,
      generated_date: documentDataAssembler.formatDate(document.generated_at),
    },
    watermark: invalidWatermark(),
    doc_title: titleFor(documentTypeCode),
    section1_title: section1TitleFor(documentTypeCode),
    terms,
    terms_section_number: termsSectionNumberFor(documentTypeCode),
    terms_section_title: termsSectionTitleFor(documentTypeCode),
    signatory: await documentDataAssembler.signatoryFromSnapshot(document),
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
  const supersededUuidName = uuidFilename('pdf');
  const supersededPath = path.join(targetDir, supersededUuidName);
  fs.writeFileSync(supersededPath, pdfBytes);

  const supersededPdfFileId = await fileStoreRepository.insertGenerated(
    null,
    orderId,
    null,
    supersededPath,
    supersededUuidName,
    `${documentTypeCode} ${filenameSafeReference} Rev.${document.client_revision_number} (SUPERSEDED).pdf`,
    pdfBytes.length,
    'application/pdf',
    null,
    false
  );

  await documentRepository.markSuperseded(documentId, supersededPdfFileId);
  await require('../repositories/auditLogRepository').log(null, 'DOCUMENT_SUPERSEDED', 'documents', documentId, 'status', document.status, 'superseded');
}

/**
 * Called right after a document is fully approved (finalizeApproval()
 * above) — every OTHER document of the same (order, type) that's still
 * sitting as 'approved' or 'sent' is now stale: only the revision that
 * was just approved may legitimately be sent to or relied on by the buyer
 * going forward. Finds every sibling revision via
 * documentRepository.allForOrderAndType() and invalidates each one still
 * in 'approved'/'sent' status (a 'draft'/'in_review'/already-'superseded'
 * sibling is left alone — nothing to invalidate).
 */
async function supersedeOtherApprovedRevisions(documentId) {
  const document = await documentRepository.find(documentId);
  if (!document || document.order_id === null) {
    return;
  }

  const siblings = await documentRepository.allForOrderAndType(document.order_id, document.document_type_id);
  for (const sibling of siblings) {
    if (sibling.id === documentId) {
      continue;
    }
    if (sibling.status === 'approved' || sibling.status === 'sent') {
      await markSuperseded(sibling.id);
    }
  }
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

/**
 * Point 2 follow-up (2026-09-30) — the internal-only "CA Financial
 * Annexure": every RODTEP/export-benefit claim and every expense (ECGC
 * insurance, inspection, CHA, transport, ...) linked to one order,
 * collected into one printable record for staff/CA use.
 *
 * This is a HARD, structural separation from every client-facing
 * document type, not a checkbox on an existing one:
 *   - Its own document_types row (CAFIN, category='internal') — the
 *     exact mechanism this app already uses to keep SUPPO/BLI/COOPREP/
 *     AMD/checklists out of documentRepository.customerFacingForOrder()
 *     (that query filters on category = 'customer_facing'), so this can
 *     never appear in the client portal's document list, whatever
 *     caController does.
 *   - Its own Nunjucks template (CAFIN/ca_financial_annexure.njk) that
 *     is NOT rendered through any shared buyer-document layout — no
 *     shared header/footer/signature-block markup with any buyer
 *     document, so it can never be visually mistaken for one even if it
 *     somehow ended up in the wrong hands. Carries a bright red
 *     "INTERNAL ONLY" banner top and bottom instead.
 *   - Stored under the order's own "internal-ca" path segment, not
 *     alongside stage-based generated documents.
 *   - file_store.internal_only = true (same flag content-parity DOCX
 *     renders already use) and excluded from the general dossier ZIP
 *     (ordersController.downloadDossier()) so a staff member holding
 *     only the broad manage_orders permission can't pull it in bulk —
 *     documentController.download() separately requires ca_module_view
 *     specifically for this one document type before serving it.
 *
 * Refused unless orders.ca_internal_doc_enabled is set (checked here
 * too, not just in the controller, as defense in depth) — see
 * orderRepository.setCaInternalDocEnabled(). No stage-gate or locked-
 * order check: unlike buyer documents, this one is often only generated
 * well after an order has closed (RODTEP can take months to actually be
 * credited), so it must remain generatable regardless of the order's own
 * lifecycle state.
 */
async function generateCaInternalAnnexure(orderId, generatedByUserId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }
  if (!order.ca_internal_doc_enabled) {
    throw new Error(`Internal CA financial annexure is not enabled for order ${orderId}.`);
  }

  const docTypeId = await documentTypeIdFor('CAFIN');
  if (!docTypeId) {
    throw new Error('CAFIN document type is not seeded.');
  }

  // mysql2 returns DECIMAL columns as strings, and JS's `+` concatenates
  // rather than adds when either operand is a string — the template's
  // running-total {% set total = total + row.amount %} would silently
  // produce garbage (or a NaN once >1 row is summed) without this
  // explicit Number() coercion up front.
  const benefits = (await caExportBenefitRepository.forOrder(orderId)).map((b) => ({
    ...b,
    claimed_amount: Number(b.claimed_amount),
    received_amount: b.received_amount !== null && b.received_amount !== undefined ? Number(b.received_amount) : null,
  }));
  const expenses = (await caExpenseRepository.forOrder(orderId)).map((e) => ({
    ...e,
    amount: Number(e.amount),
    tds_amount: e.tds_amount !== null && e.tds_amount !== undefined ? Number(e.tds_amount) : null,
  }));
  // Added alongside the Order Profitability Sheet (docs/schema.sql Section
  // AP) — supplier pricing is exactly the kind of data this document
  // exists for (internal-only, never client-facing) and was previously
  // missing from it entirely.
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  const costEntries = (await orderCostEntryRepository.forOrder(orderId)).map((c) => ({
    ...c,
    amount_inr: Number(c.amount_inr),
  }));
  const profitability = await orderProfitabilityService.computeForOrder(orderId);

  const referenceNumberService = require('./referenceNumberService');
  const revisionNumber = await referenceNumberService.nextDocumentRevisionNumber(orderId, docTypeId);
  const existing = await documentRepository.findLatestForOrderAndType(orderId, docTypeId);
  const documentReference = existing ? existing.document_reference : await referenceNumberService.generateDocumentRef(docTypeId);

  const generatedByUser = await userRepository.findById(generatedByUserId);

  const context = {
    company: await documentDataAssembler.companyBlock(),
    order: {
      order_reference: order.order_reference,
      company_legal_name: order.company_legal_name,
    },
    meta: {
      document_reference: documentReference,
      revision_number: revisionNumber,
      generated_date: formatNowWithTime(),
      generated_by_name: (generatedByUser && generatedByUser.name) || 'Unknown',
    },
    benefits,
    expenses,
    supplier_po: supplierPo,
    cost_entries: costEntries,
    profitability,
  };

  const twig = templatesEnvironment();
  const html = twig.render('CAFIN/ca_financial_annexure.njk', context);
  const pdfBytes = await renderPdfFromHtml(html);

  const storageBase = (env.get('STORAGE_BASE_PATH', '') || '').replace(/\/+$/, '');
  const clientNumber = sanitizePathSegment(order.client_unique_number);
  const orderRef = sanitizePathSegment(order.order_reference);
  const targetDir = path.join(storageBase, 'clients', clientNumber, orderRef, 'internal-ca', 'generated');
  fs.mkdirSync(targetDir, { recursive: true });

  const filenameSafeReference = documentReference.replace(/\//g, '-');
  const uuidName = uuidFilename('pdf');
  const pdfPath = path.join(targetDir, uuidName);
  fs.writeFileSync(pdfPath, pdfBytes);

  const pdfFileId = await fileStoreRepository.insertGenerated(
    null,
    orderId,
    null,
    pdfPath,
    uuidName,
    `CAFIN ${filenameSafeReference} (INTERNAL ONLY - not for client).pdf`,
    pdfBytes.length,
    'application/pdf',
    generatedByUserId,
    true // internal_only — never emailed/sent to buyer, never in the client portal
  );

  const documentId = await documentRepository.create(orderId, docTypeId, documentReference, revisionNumber, pdfFileId, null, generatedByUserId);

  return {
    document_id: documentId,
    document_reference: documentReference,
    revision_number: revisionNumber,
    pdf_file_id: pdfFileId,
  };
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
 * Document-fidelity internal DOCX — delegates to docxDocumentBuilder.js,
 * the Node sibling of the PHP stack's DocxDocumentBuilder.php, which
 * visually matches the company's real Word templates (fonts, colors,
 * tables, signature block, logo, watermark) using the `docx` npm package.
 */
async function renderDocx(targetPath, documentTypeCode, context) {
  const buffer = await docxDocumentBuilder.render(documentTypeCode, context);
  fs.writeFileSync(targetPath, buffer);
}

function titleFor(code) {
  const map = {
    QT: 'QUOTATION',
    ANNEXA: 'ANNEXURE A — PRODUCT TECHNICAL SPECIFICATIONS',
    PI: 'PROFORMA INVOICE',
    OC: 'ORDER CONFIRMATION',
    BUYERPO: 'PURCHASE ORDER',
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
    SUPPO: 'BUYER (NexaCrest International Private Limited)',
    FDN: 'FROM (SELLER / EXPORTER)',
    CI: 'EXPORTER / SELLER',
    BLI: 'SHIPPER / EXPORTER (APPEARS ON BL EXACTLY AS WRITTEN)',
    AMD: 'PARTIES TO THIS AMENDMENT',
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

function formatNowWithTime() {
  const d = new Date();
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  return `${formatNow()} ${hh}:${mm}`;
}

module.exports = {
  generate,
  finalizeApproval,
  markSuperseded,
  supersedeOtherApprovedRevisions,
  generateAmendment,
  generateCaInternalAnnexure,
  documentTypeIdFor,
  downstreamDocumentsAtRisk,
  templatesEnvironment,
  StageGateBlockedError,
  // Exported for tests only (Point 8 watermark fix) — Node has no
  // PHP-style reflection to reach a private method, so this is the
  // idiomatic equivalent for exercising the mode/show_image logic
  // directly instead of only through a full generate() pipeline.
  watermarkFromRow,
  // Exported for tests only — Puppeteer is stubbed out under Jest, so
  // Node tests can't exercise generate()'s real PDF path the way PHP's
  // PHPUnit tests do (dompdf runs cheaply in-process there). Rendering
  // the Nunjucks template directly with titleFor/section1TitleFor/
  // templateFileFor is the equivalent way to verify actual document
  // output without a real browser.
  titleFor,
  section1TitleFor,
  templateFileFor,
};
