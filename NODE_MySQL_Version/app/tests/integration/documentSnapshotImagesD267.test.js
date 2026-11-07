'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const crypto = require('crypto');
const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const images = require('../../src/services/renderContextImages');
const { createTestClient, createTestOrder, allowHandTypedGoodsLines } = require('../support/fixtures');

/**
 * D-267 (pending owner double confirmation) — port of PHP DocumentSnapshotImagesD267Test: a
 * document's stored render snapshot keeps each image once, by SHA-256, in document_image_blobs —
 * it comes back identical, a missing or altered image is refused, old inline snapshots still
 * load, and the image rows can never be changed or deleted.
 */
const rand = () => crypto.randomBytes(4).toString('hex');
const sha = (s) => crypto.createHash('sha256').update(s, 'utf8').digest('hex');

function dataUri(seed, bytes) {
  const block = crypto.createHash('sha256').update(seed).digest();
  const raw = Buffer.alloc(bytes);
  for (let i = 0; i < bytes; i += 32) block.copy(raw, i, 0, Math.min(32, bytes - i));
  return `data:image/png;base64,${raw.toString('base64')}`;
}

function context() {
  const seal = dataUri(`seal-${rand()}`, 400000);
  return {
    meta: { document_reference: 'NCI/PI/TEST', note: 'data: not an image' },
    assets: { logo_data_uri: dataUri(`logo-${rand()}`, 20000), seal_data_uri: seal, signature_data_uri: null },
    signatory: { name: 'Managing Director', company_seal_data_uri: seal, seal_data_uri: dataUri(`dseal-${rand()}`, 30000) },
    watermark: { enabled: true, opacity: 0.35, image_data_uri: dataUri(`wm-${rand()}`, 10000) },
    lines: [{ qty: '120.0', rate: 45.5 }, { qty: '80', rate: 32.25 }],
  };
}

async function documentId() {
  const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
  const u = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :e, NULL, 'x', :r, 1, 0, 0)`, { e: `jest-d267-${rand()}@nexacrest.test`, r: role.id });
  const orderId = await createTestOrder(await createTestClient(`d267-${rand()}@example.test`), 'CIF', 'Standard — New Buyer', 'USD');
  await orderProductRepository.add(orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
  const r = await documentGenerationService.generate(orderId, 'PI', u.insertId, null, false, true);
  return Number(r.document_id);
}

const raw = async (id) => (await db.queryOne('SELECT render_context_json AS j FROM documents WHERE id = :id', { id })).j;
const setRaw = (id, json) => db.execute('UPDATE documents SET render_context_json = :j WHERE id = :id', { j: json, id });

describe('D-267 document snapshot images', () => {
  jest.setTimeout(60000);
  beforeAll(async () => { await allowHandTypedGoodsLines(); });
  afterAll(async () => { await db.pool.end(); });

  it('keeps references, not images, and comes back identical', async () => {
    const id = await documentId();
    const ctx = context();
    const inline = JSON.stringify(ctx).length;
    await documentRepository.storeRenderContext(id, ctx);
    const stored = await raw(id);
    expect(stored).not.toContain(';base64,');
    expect(stored).toContain('"__image_ref":"sha256:');
    expect(stored.length).toBeLessThan(inline / 50);
    expect(await documentRepository.renderContext(id)).toEqual(ctx);
  });

  it('stores an image used twice only once', async () => {
    const ctx = context();
    const packed = await images.pack(ctx);
    expect(packed.assets.seal_data_uri).toEqual(packed.signatory.company_seal_data_uri);
    await images.pack(ctx);
    const s = packed.assets.seal_data_uri.__image_ref.slice(7);
    expect(s).toBe(sha(ctx.assets.seal_data_uri));
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM document_image_blobs WHERE sha256 = :s', { s })).n)).toBe(1);
  });

  it('refuses a missing image, naming the document and the image', async () => {
    const id = await documentId();
    const missing = 'ab'.repeat(32);
    await setRaw(id, JSON.stringify({ assets: { seal_data_uri: { __image_ref: `sha256:${missing}`, store: 'document_image_blobs', mime: 'image/png', length: 10 } } }));
    await expect(documentRepository.renderContext(id)).rejects.toThrow(
      `Document #${id} cannot be re-rendered: its stored image "assets.seal_data_uri" (sha256 ${missing}) is missing from document_image_blobs. Nothing was changed; restore that image from a backup.`
    );
  });

  it('refuses an altered image', async () => {
    const id = await documentId();
    const claimed = sha(`the original seal ${rand()}`);
    await db.execute('INSERT INTO document_image_blobs (sha256, mime_type, byte_length, data_uri) VALUES (:s, :m, :l, :d)',
      { s: claimed, m: 'image/png', l: 30, d: 'data:image/png;base64,QUxURVJFRA==' });
    await setRaw(id, JSON.stringify({ signatory: { seal_data_uri: { __image_ref: `sha256:${claimed}`, store: 'document_image_blobs', mime: 'image/png', length: 30 } } }));
    await expect(documentRepository.renderContext(id)).rejects.toThrow(
      `Document #${id} cannot be re-rendered: its stored image "signatory.seal_data_uri" does not match its SHA-256 (${claimed}) — it has been altered. Nothing was changed; restore that image from a backup.`
    );
  });

  it('still loads a snapshot stored before the change, as it was', async () => {
    const id = await documentId();
    const ctx = context();
    await setRaw(id, JSON.stringify(ctx));
    expect(await documentRepository.renderContext(id)).toEqual(ctx);
  });

  it('never lets a stored image be changed or deleted', async () => {
    const packed = await images.pack({ x: dataUri(`lock-${rand()}`, 100) });
    const s = packed.x.__image_ref.slice(7);
    for (const q of ["UPDATE document_image_blobs SET data_uri = 'data:image/png;base64,AA==' WHERE sha256 = :s", 'DELETE FROM document_image_blobs WHERE sha256 = :s']) {
      await expect(db.execute(q, { s })).rejects.toThrow('Document images are kept for record');
    }
  });

  it('rebuilds the final copy from the new snapshot', async () => {
    const id = await documentId();
    expect(await raw(id)).not.toContain(';base64,');
    await documentGenerationService.finalizeApproval(id);
    expect((await documentRepository.find(id)).pdf_file_id).toBeTruthy();
  });
});
