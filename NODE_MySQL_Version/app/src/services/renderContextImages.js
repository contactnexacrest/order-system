'use strict';

const crypto = require('crypto');
const db = require('../config/db');

/**
 * D-267 (pending owner double confirmation) — port of PHP RenderContextImages.
 *
 * A stored render context (documents.render_context_json — what a revision was
 * rendered from, so its FINAL / SUPERSEDED copies are rebuilt from exactly what
 * was reviewed and sent) used to embed every image (company seal, signatory
 * seal, signature, logo, watermark) as a base64 data URI — up to 3.7 MB per
 * document, repeated in every document.
 *
 * Now each image is kept ONCE, exactly as embedded (the very same data URI
 * string), in the insert-only table document_image_blobs, keyed by its SHA-256.
 * The stored context holds only a small reference:
 *   {"__image_ref": "sha256:<64 hex>", "store": "document_image_blobs", "mime": "image/png", "length": <bytes>}
 * Re-rendering puts the identical data URI back after checking its SHA-256. A
 * missing or altered image stops the re-render with an error naming the
 * document and the image — never a silent substitution.
 *
 * Contexts stored before this change still hold the data URIs inline and are
 * left untouched (they render exactly as before).
 */
const REF_KEY = '__image_ref';
const STORE = 'document_image_blobs';
const DATA_URI = /^data:([a-z]+\/[a-z0-9.+-]+);base64,/i;

const sha256 = (s) => crypto.createHash('sha256').update(s, 'utf8').digest('hex');

async function packValue(value) {
  if (Array.isArray(value)) {
    const out = [];
    for (const v of value) out.push(await packValue(v));
    return out;
  }
  if (value && typeof value === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(value)) out[k] = await packValue(v);
    return out;
  }
  if (typeof value === 'string') {
    const m = DATA_URI.exec(value);
    if (m) {
      const sha = sha256(value);
      const mime = m[1].toLowerCase();
      const length = Buffer.byteLength(value, 'utf8');
      await db.execute(
        'INSERT IGNORE INTO document_image_blobs (sha256, mime_type, byte_length, data_uri) VALUES (:s, :m, :l, :d)',
        { s: sha, m: mime, l: length, d: value }
      );
      return { [REF_KEY]: `sha256:${sha}`, store: STORE, mime, length };
    }
  }
  return value;
}

/** Replaces every embedded image with a reference, saving the image once. */
async function pack(context) {
  return packValue(context);
}

async function resolve(ref, documentId, path) {
  const sha = String(ref[REF_KEY]).slice('sha256:'.length);
  const row = await db.queryOne('SELECT data_uri FROM document_image_blobs WHERE sha256 = :s', { s: sha });
  const dataUri = row ? row.data_uri : null;
  if (typeof dataUri !== 'string' || dataUri === '') {
    throw new Error(`Document #${documentId} cannot be re-rendered: its stored image "${path}" (sha256 ${sha}) is missing from ${STORE}. Nothing was changed; restore that image from a backup.`);
  }
  const actual = sha256(dataUri);
  if (actual.length !== sha.length || !crypto.timingSafeEqual(Buffer.from(actual), Buffer.from(sha))) {
    throw new Error(`Document #${documentId} cannot be re-rendered: its stored image "${path}" does not match its SHA-256 (${sha}) — it has been altered. Nothing was changed; restore that image from a backup.`);
  }
  return dataUri;
}

async function unpackValue(value, documentId, path) {
  if (Array.isArray(value)) {
    const out = [];
    for (let i = 0; i < value.length; i += 1) out.push(await unpackValue(value[i], documentId, path === '' ? String(i) : `${path}.${i}`));
    return out;
  }
  if (value && typeof value === 'object') {
    if (typeof value[REF_KEY] === 'string') return resolve(value, documentId, path);
    const out = {};
    for (const [k, v] of Object.entries(value)) out[k] = await unpackValue(v, documentId, path === '' ? k : `${path}.${k}`);
    return out;
  }
  return value;
}

/** Puts each referenced image back exactly as stored; throws on a missing or altered image. */
async function unpack(context, documentId) {
  return unpackValue(context, documentId, '');
}

module.exports = { pack, unpack, REF_KEY, STORE };
