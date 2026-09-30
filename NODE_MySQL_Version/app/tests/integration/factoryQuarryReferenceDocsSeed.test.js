'use strict';

const fs = require('fs');
const path = require('path');
const referenceLibraryRepository = require('../../src/repositories/referenceLibraryRepository');
const db = require('../../src/config/db');

/**
 * These 4 client-verification documents (Quarry SOP, Factory SOP, Factory
 * Processing Agreement template, Quarry Block Supply Agreement template)
 * used to exist only as rows hand-inserted into the two live dev
 * databases, pointing at dev-machine-specific absolute paths, with the
 * actual files sitting only in storage/internal/reference_library/ —
 * which is gitignored. A fresh clone or a production deploy from git
 * alone would have neither the rows nor the files. Pins both: seed.sql
 * must create exactly these 4 rows, and the file each one's file_path
 * resolves to (once __STORAGE_BASE_PATH__ is substituted, same as
 * production's post-import step) must actually exist in the repo.
 */
describe('Factory/Quarry reference documents seed', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const expectedTitles = [
    'Quarry SOP – Block Selection & Reservation',
    'Factory SOP – Processing, QC & Packing',
    'Factory Processing Agreement (Template)',
    'Quarry Block Supply Agreement (Template)',
  ];

  it('seed creates all four documents with files attached', async () => {
    const all = await referenceLibraryRepository.all();
    const byTitle = Object.fromEntries(all.map((row) => [row.title, row]));

    for (const title of expectedTitles) {
      expect(byTitle[title]).toBeTruthy();
      expect(byTitle[title].file_path).toBeTruthy();
      expect(byTitle[title].file_path).toContain('__STORAGE_BASE_PATH__/assets/reference_library/');
    }
  });

  it('each seeded file path resolves to a real committed file', async () => {
    const storageBase = (process.env.STORAGE_BASE_PATH || '').replace(/\/$/, '');
    const all = await referenceLibraryRepository.all();

    let checked = 0;
    for (const row of all) {
      if (!row.file_path || !row.file_path.includes('__STORAGE_BASE_PATH__/assets/reference_library/')) {
        continue;
      }
      const resolved = row.file_path.replace('__STORAGE_BASE_PATH__', storageBase);
      expect(fs.existsSync(resolved)).toBe(true);
      checked += 1;
    }

    expect(checked).toBe(4);
  });
});
