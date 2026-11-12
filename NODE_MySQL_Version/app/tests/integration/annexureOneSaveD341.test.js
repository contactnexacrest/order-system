'use strict';

const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const orderAnnexureRepository = require('../../src/repositories/orderAnnexureRepository');
const orderAnnexureTermsRepository = require('../../src/repositories/orderAnnexureTermsRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const annexureChoice = require('../../src/services/annexureChoice');
const { createTestClient, createTestOrder, createTestDocument, createTestFile } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

/**
 * Owner decision D-341 (12 Nov 2026, "will go with recommendation" + "one save for
 * the whole page"): the order page's Step 1 annexure buttons, the "generate the new
 * revision" banner, Annexure B from the Commercial Invoice step on, the empty
 * Annexure B start, the CI step's "Annexure B not made" note, and one "Save all
 * changes" for the whole annexure page. Twin of PHP AnnexureOneSaveD341Test.php —
 * same wording.
 */

// A tiny real PNG (1 x 1).
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', 'base64');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

async function entry(orderId, which, name) {
  return orderAnnexureRepository.createProduct(orderId, {
    name, description: `Desc ${name}`, dimensions: '10 x 10 cm', finish: 'Polished', components: '', technical_notes: '',
  }, which);
}

/** Stage 8 (the Commercial Invoice step) open. */
async function reachCiStage(orderId) {
  await db.execute(
    "UPDATE order_stages os JOIN stages_master sm ON sm.id = os.stage_id SET os.status = 'in_progress' WHERE os.order_id = :o AND sm.stage_number = 8",
    { o: orderId }
  );
}

describe('D-341 — one save for the annexure page, Annexure B from the CI step', () => {
  let agent;
  let csrf;
  let adminId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginRes = await agent.post('/login').type('form').send({ _csrf: extractCsrf(loginPage.text), email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
    adminId = (await db.queryOne('SELECT id FROM users WHERE email = :e', { e: TEST_ADMIN_EMAIL })).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function page(url) {
    const res = await agent.get(url);
    expect(res.status).toBeLessThan(500);
    csrf = extractCsrf(res.text);
    return res.text;
  }

  async function flashOf(url) {
    const html = await page(url);
    return html;
  }

  // ---- 1. Order page, Step 1 ----------------------------------------------------

  it('Step 1 shows "Edit Annexure A ›" and Generate, then Regenerate once an ANNEXA exists', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setIncludeAnnexureA(orderId, true);

    let html = await page(`/orders/${orderId}`);
    expect(html).toContain('Edit Annexure A &rsaquo;</a>');
    expect(html).not.toContain('Manage Annexure A product entries');
    expect(html).toContain('>Generate Annexure A</button>');

    await createTestDocument(orderId, 'ANNEXA', 'draft');
    html = await page(`/orders/${orderId}`);
    expect(html).toContain('>Regenerate Annexure A (new revision)</button>');
  });

  // ---- 2. The "generate the new revision" banner -------------------------------

  it('shows the banner only when Annexure A changed after the approved revision', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const entryId = await entry(orderId, 'A', 'Slab');
    const docId = await createTestDocument(orderId, 'ANNEXA', 'approved');
    await db.execute('UPDATE documents SET revision_number = 2, generated_at = NOW() + INTERVAL 1 HOUR WHERE id = :id', { id: docId });

    await auditLogRepository.log(adminId, 'ANNEXURE_PRODUCT_UPDATED', 'order_annexure_products', entryId, 'name', null, 'Slab');
    expect(await page(`/orders/${orderId}/annexure`)).not.toContain('Generate the new revision');

    await db.execute('UPDATE documents SET generated_at = NOW() - INTERVAL 1 HOUR WHERE id = :id', { id: docId });
    const html = await page(`/orders/${orderId}/annexure`);
    expect(html).toContain('You changed Annexure A after Rev.2 was approved — generate the new revision, then approve and send it.');
    expect(html).toContain(`action="/orders/${orderId}/documents/generate"`);
    expect(html).toContain('name="document_type" value="ANNEXA"');
    expect(html).toContain('Generate the new revision &rsaquo;</button>');

    await db.execute("UPDATE documents SET status = 'draft' WHERE id = :id", { id: docId });
    expect(await page(`/orders/${orderId}/annexure`)).not.toContain('Generate the new revision');
  });

  it('a removed entry counts as a change; a change to Annexure B does not', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const entryId = await entry(orderId, 'A', 'Slab');
    const docId = await createTestDocument(orderId, 'ANNEXA', 'sent');
    await db.execute('UPDATE documents SET generated_at = NOW() - INTERVAL 1 HOUR WHERE id = :id', { id: docId });
    expect(await annexureChoice.staleApprovedAnnexA(orderId)).toBeNull();

    await reachCiStage(orderId);
    const bEntry = await entry(orderId, 'B', 'Shipped slab');
    await page(`/orders/${orderId}/annexure?which=B`);
    await agent.post(`/orders/${orderId}/annexure/save-all`).field('_csrf', csrf).field('which', 'B').field('mode', 'SPEC')
      .field(`entries[${bEntry}][name]`, 'Shipped slab 2');
    expect(await annexureChoice.staleApprovedAnnexA(orderId)).toBeNull();

    await page(`/orders/${orderId}/annexure`);
    await agent.post(`/orders/${orderId}/annexure/products/${entryId}/delete`).type('form').send({ _csrf: csrf });
    expect(await annexureChoice.staleApprovedAnnexA(orderId)).not.toBeNull();
  });

  // ---- 3. Annexure B only from the Commercial Invoice step ---------------------

  it('hides Annexure B before the CI step, shows it after or when B has content', async () => {
    const orderId = await createTestOrder(await createTestClient());

    let html = await page(`/orders/${orderId}/annexure`);
    expect(html).not.toContain('Annexure B (as shipped) &rsaquo;');
    expect(html).toContain('Annexure B can be made at the Commercial Invoice step.');

    html = await page(`/orders/${orderId}/annexure?which=B`);
    expect(html).toContain('Annexure B can be made at the Commercial Invoice step.');
    expect(html).toContain('&lsaquo; Back to Annexure A</a>');
    expect(html).not.toContain('Save all changes');
    expect(html).not.toContain('Start Annexure B from a copy of Annexure A');

    const refused = await agent.post(`/orders/${orderId}/annexure/copy-from-a`).type('form').send({ _csrf: csrf });
    expect(refused.status).toBe(302);
    expect(refused.headers.location).toBe(`/orders/${orderId}/annexure`);
    expect(await orderAnnexureRepository.countFor(orderId, 'B')).toBe(0);
    await page(`/orders/${orderId}/annexure`); // takes the flash

    await entry(orderId, 'B', 'Made earlier');
    expect(await page(`/orders/${orderId}/annexure`)).toContain('Annexure B (as shipped) &rsaquo;');
    expect(await page(`/orders/${orderId}/annexure?which=B`)).toContain('Save all changes');

    const other = await createTestOrder(await createTestClient());
    await reachCiStage(other);
    html = await page(`/orders/${other}/annexure`);
    expect(html).toContain('Annexure B (as shipped) &rsaquo;');
    expect(html).not.toContain('Annexure B can be made at the Commercial Invoice step.');
  });

  // ---- 4. The empty Annexure B start --------------------------------------------

  it('an empty Annexure B offers the copy first, then "Start empty instead"', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await reachCiStage(orderId);
    await entry(orderId, 'A', 'Quoted slab');

    let html = await page(`/orders/${orderId}/annexure?which=B`);
    expect(html).toContain('>Start Annexure B from a copy of Annexure A</button>');
    expect(html).toContain(`action="/orders/${orderId}/annexure/copy-from-a"`);
    expect(html).not.toContain('data-confirm="Replace Annexure B');
    expect(html).toContain(`href="/orders/${orderId}/annexure?which=B&amp;start=empty">Start empty instead</a>`);
    expect(html).not.toContain('Save all changes');

    html = await page(`/orders/${orderId}/annexure?which=B&start=empty`);
    expect(html).toContain('Save all changes');
    expect(html).toContain('Copy everything from Annexure A');

    const res = await agent.post(`/orders/${orderId}/annexure/copy-from-a`).type('form').send({ _csrf: csrf });
    expect(res.headers.location).toBe(`/orders/${orderId}/annexure?which=B`);
    expect(await orderAnnexureRepository.countFor(orderId, 'B')).toBe(1);
    html = await page(`/orders/${orderId}/annexure?which=B`);
    expect(html).not.toContain('Start Annexure B from a copy of Annexure A');
    expect(html).toContain('data-confirm="Replace Annexure B with a copy of Annexure A?"');
  });

  // ---- 5. The CI step note -------------------------------------------------------

  it('the CI step says "Annexure B not made" with the copy link', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await reachCiStage(orderId);

    // Owner, 12 Nov 2026: an order that never had an Annexure A shows no annexure note at all.
    let html = await page(`/orders/${orderId}`);
    expect(html).not.toContain('Annexure B not made');
    expect(html).not.toContain('Make Annexure B (copy of A)');

    await orderRepository.setIncludeAnnexureA(orderId, true);
    await entry(orderId, 'A', 'Quoted slab');
    html = await page(`/orders/${orderId}`);
    expect(html).toContain('Annexure B not made — Annexure A will be printed.');
    expect(html).toContain('form="ci-annexure-b-copy" class="btn-link">Make Annexure B (copy of A) &rsaquo;</button>');
    expect(html).toMatch(new RegExp(`<form method="post" action="/orders/${orderId}/annexure/copy-from-a" id="ci-annexure-b-copy"`));

    await entry(orderId, 'B', 'Shipped slab');
    html = await page(`/orders/${orderId}`);
    expect(html).not.toContain('Annexure B not made');
  });

  // ---- 6. One save for the whole page ---------------------------------------------

  it('"Save all changes" saves the mode, two entries, the terms and an image in one go', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const one = await entry(orderId, 'A', 'Slab one');
    const two = await entry(orderId, 'A', 'Slab two');
    await page(`/orders/${orderId}/annexure`);

    const res = await agent.post(`/orders/${orderId}/annexure/save-all`)
      .field('_csrf', csrf).field('which', 'A').field('mode', 'BOTH')
      .field('content_html', '<p>Packed in <b>wooden crates</b></p><script>x</script>')
      .field(`entries[${one}][name]`, 'Slab one (edited)').field(`entries[${one}][dimensions]`, '200 x 100 cm').field(`entries[${one}][finish]`, 'Honed')
      .field(`entries[${one}][description]`, 'New desc').field(`entries[${one}][components]`, '1. Top').field(`entries[${one}][technical_notes]`, 'Keep dry')
      .field(`entries[${two}][name]`, 'Slab two').field(`entries[${two}][dimensions]`, '50 x 50 cm').field(`entries[${two}][finish]`, 'Polished')
      .field(`entries[${two}][description]`, 'Desc Slab two').field(`entries[${two}][components]`, '').field(`entries[${two}][technical_notes]`, 'Fragile')
      .attach(`images[${two}]`, PNG, { filename: 'slab.png', contentType: 'image/png' });
    expect(res.status).toBe(302);
    expect(res.headers.location).toBe(`/orders/${orderId}/annexure`);
    expect(await page(`/orders/${orderId}/annexure`)).toContain('All changes to Annexure A saved.');

    expect((await orderRepository.find(orderId)).annexure_mode).toBe('BOTH');
    const saved = await orderAnnexureRepository.findProduct(one);
    expect([saved.name, saved.dimensions, saved.finish, saved.description, saved.components, saved.technical_notes])
      .toEqual(['Slab one (edited)', '200 x 100 cm', 'Honed', 'New desc', '1. Top', 'Keep dry']);
    expect((await orderAnnexureRepository.findProduct(two)).dimensions).toBe('50 x 50 cm');
    const terms = String((await orderAnnexureTermsRepository.find(orderId, 'A')).content_html);
    expect(terms).toContain('<b>wooden crates</b>');
    expect(terms).not.toContain('<script');
    const images = (await orderAnnexureRepository.forOrder(orderId, 'A')).find((p) => Number(p.id) === Number(two)).images;
    expect(images.map((i) => i.original_filename)).toEqual(['slab.png']);

    const count = async (action) => Number((await db.queryOne(
      `SELECT COUNT(*) AS c FROM audit_log WHERE action_type = :a AND ((entity_type = 'order' AND entity_id = :o) OR (entity_type = 'order_annexure_products' AND entity_id IN (:one, :two)) OR (entity_type = 'order_annexure_images' AND entity_id = :img))`,
      { a: action, o: orderId, one, two, img: images[0].id }
    )).c);
    expect(await count('ANNEXURE_PRODUCT_UPDATED')).toBe(2);
    expect(await count('ANNEXURE_TERMS_UPDATED')).toBe(1);
    expect(await count('ANNEXURE_MODE_UPDATED')).toBe(1);
    expect(await count('ANNEXURE_IMAGE_UPLOADED')).toBe(1);
    for (const img of images) {
      const row = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: img.file_id });
      try { fs.unlinkSync(row.server_path); } catch (e) { /* already gone */ }
    }
  });

  it('a missing name saves nothing and shows the message beside the field', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const one = await entry(orderId, 'A', 'Slab one');
    const two = await entry(orderId, 'A', 'Slab two');
    await page(`/orders/${orderId}/annexure`);

    const res = await agent.post(`/orders/${orderId}/annexure/save-all`)
      .field('_csrf', csrf).field('which', 'A').field('mode', 'TERMS').field('content_html', '<p>New terms</p>')
      .field(`entries[${one}][name]`, 'Slab one (edited)').field(`entries[${one}][dimensions]`, '1 x 1 cm')
      .field(`entries[${two}][name]`, '   ').field(`entries[${two}][dimensions]`, '2 x 2 cm');
    expect(res.status).toBe(422);
    expect((await orderAnnexureRepository.findProduct(one)).name).toBe('Slab one');
    expect((await orderRepository.find(orderId)).annexure_mode).toBe('SPEC');
    expect(await orderAnnexureTermsRepository.find(orderId, 'A')).toBeNull();
    expect(res.text).toContain('Nothing was saved — correct the fields marked below');
    expect(res.text).toMatch(new RegExp(`name="entries\\[${two}\\]\\[name\\]"[^>]*field-invalid[^>]*><small class="reason-error form-field-error">Product name is required\\.</small>`));
    expect(res.text).toContain('value="Slab one (edited)"');
    expect(res.text).toContain('<p>New terms</p>');
  });

  it('a wrong image type is refused beside its field and nothing is saved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const one = await entry(orderId, 'A', 'Slab one');
    await page(`/orders/${orderId}/annexure`);

    const res = await agent.post(`/orders/${orderId}/annexure/save-all`)
      .field('_csrf', csrf).field('which', 'A').field('mode', 'SPEC').field(`entries[${one}][name]`, 'Renamed')
      .attach(`images[${one}]`, Buffer.from('not really an image'), { filename: 'notes.txt', contentType: 'text/plain' });
    expect(res.status).toBe(422);
    expect(res.text).toContain('File type .txt is not allowed for this upload (allowed: jpg,jpeg,png,webp).');
    expect((await orderAnnexureRepository.findProduct(one)).name).toBe('Slab one');
    expect((await orderAnnexureRepository.forOrder(orderId, 'A'))[0].images).toEqual([]);
  });

  it('has one guarded form, top and bottom buttons, and separate removal forms that still work', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const one = await entry(orderId, 'A', 'Slab one');
    const imageId = await orderAnnexureRepository.addImage(one, await createTestFile(orderId, null));

    const html = await page(`/orders/${orderId}/annexure`);
    expect(html.split('>Save all changes</button>').length - 1).toBe(2);
    expect(html).toContain(`action="/orders/${orderId}/annexure/save-all" enctype="multipart/form-data" id="annexure-save-all" data-unsaved-guard`);
    expect(html).toContain(`name="images[${one}]"`);
    expect(html).not.toContain('>Upload Image</button>');
    expect(html).not.toContain('>Save Additional Terms</button>');
    expect(html).not.toContain('>Save</button>');

    const start = html.indexOf('id="annexure-save-all"');
    const big = html.slice(start, html.indexOf('</form>', start));
    expect(big).not.toContain('<form');
    expect(big).toContain(`form="annexure-entry-remove-${one}"`);
    expect(big).toContain(`form="annexure-image-remove-${imageId}"`);
    expect(html).toContain(`action="/orders/${orderId}/annexure/products/${one}/delete" id="annexure-entry-remove-${one}"`);
    expect(html).toContain(`action="/orders/${orderId}/annexure/images/${imageId}/remove" id="annexure-image-remove-${imageId}"`);

    await agent.post(`/orders/${orderId}/annexure/images/${imageId}/remove`).type('form').send({ _csrf: csrf });
    expect((await orderAnnexureRepository.forOrder(orderId, 'A'))[0].images).toEqual([]);
    await agent.post(`/orders/${orderId}/annexure/products/${one}/delete`).type('form').send({ _csrf: csrf });
    expect(await orderAnnexureRepository.countFor(orderId, 'A')).toBe(0);
  });

  it('app.js has the unsaved-changes guard', () => {
    const js = fs.readFileSync(path.join(__dirname, '../../public/js/app.js'), 'utf8');
    expect(js).toContain('form[data-unsaved-guard]');
    expect(js).toContain('You have unsaved changes on this page — leave without saving?');
    expect(js).toContain('beforeunload');
  });
});
