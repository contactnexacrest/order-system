'use strict';

const fs = require('fs');
const env = require('../../src/config/env');

/**
 * 90-item backlog "storage_base_path precedence": every call site used to
 * inline its own fallback for a missing STORAGE_BASE_PATH, and they had
 * drifted — four controllers (signatory/referenceDoc/asset/products)
 * computed `path.join(__dirname, '..', '..', 'storage')`, which from
 * src/controllers/ is only two levels up and lands on the nonexistent
 * app/storage, not the real storage/ sibling of app/. Everywhere else
 * (documentGenerationService, sampleDataService, fileUploadService,
 * testModeRepository, sampleDataRepository) fell back to '' instead,
 * silently resolving every path built from it against the filesystem
 * root. env.storageBasePath() is now the one place this fallback is
 * computed, so every call site agrees on the real directory whenever
 * .env doesn't set one.
 */
describe('env.storageBasePath()', () => {
  const original = process.env.STORAGE_BASE_PATH;

  afterEach(() => {
    if (original === undefined) {
      delete process.env.STORAGE_BASE_PATH;
    } else {
      process.env.STORAGE_BASE_PATH = original;
    }
  });

  it('falls back to the real, existing storage directory when unset', () => {
    delete process.env.STORAGE_BASE_PATH;

    const result = env.storageBasePath();

    expect(result).not.toBe('');
    expect(result).toMatch(/\/NODE_MySQL_Version\/storage$/);
    expect(fs.existsSync(result)).toBe(true);
  });

  it('uses the real .env value when one is set', () => {
    process.env.STORAGE_BASE_PATH = '/custom/deployment/storage';
    expect(env.storageBasePath()).toBe('/custom/deployment/storage');
  });
});
