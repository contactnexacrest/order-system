'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Port of App\Controllers\GalleryController — a pure disk-backed
 * knowledgebase gallery. Reads whatever folders and files exist under
 * docs/gallery live off disk, exactly like sopController reads docs/SOP.
 * Deliberately no database, no upload form, and no category list
 * hardcoded anywhere: files are added directly on the server's
 * filesystem (e.g. via FTP) and show up immediately, folders double as
 * categories with zero code change. Read-only, same visibility as the
 * SOP viewer — any logged-in staff member (see server.js) — since this
 * is reference material, not an action surface.
 */

const ROOT_DIR = path.join(__dirname, '..', '..', '..', 'docs', 'gallery');

const MIME_BY_EXT = {
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.pdf': 'application/pdf',
};

const IMAGE_EXTENSIONS = new Set(['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg']);

function isImage(filename) {
  return IMAGE_EXTENSIONS.has(path.extname(filename).toLowerCase());
}

/**
 * Resolves a user-supplied relative path against the gallery root and
 * returns its real, on-disk path only if it stays inside that root —
 * null otherwise. realpathSync collapses any '../' and resolves symlinks
 * before the containment check, same "belt and braces" reasoning as
 * sopController's show()/asset(), generalized here to arbitrary folder
 * depth since the gallery, unlike SOP's flat images folder, supports
 * nested category folders.
 */
function resolveSafePath(relative) {
  if (typeof relative !== 'string' || relative.includes('\0')) {
    return null;
  }
  const cleaned = relative.split('\\').join('/').replace(/^\/+|\/+$/g, '');

  let root;
  try {
    root = fs.realpathSync(ROOT_DIR);
  } catch {
    return null;
  }
  if (cleaned === '') {
    return root;
  }

  let candidate;
  try {
    candidate = fs.realpathSync(path.join(root, cleaned));
  } catch {
    return null;
  }
  if (candidate === root || candidate.startsWith(root + path.sep)) {
    return candidate;
  }
  return null;
}

async function index(req, res) {
  let relative = String(req.query.p || '').replace(/^\/+|\/+$/g, '');
  let dir = resolveSafePath(relative);

  if (!dir || !fs.statSync(dir).isDirectory()) {
    dir = resolveSafePath('');
    relative = '';
  }

  const folders = [];
  const files = [];
  if (dir) {
    const entries = fs.readdirSync(dir);
    for (const entry of entries) {
      if (entry.startsWith('.')) continue;
      const full = path.join(dir, entry);
      const entryRelative = relative === '' ? entry : `${relative}/${entry}`;
      const stat = fs.statSync(full);
      if (stat.isDirectory()) {
        folders.push({ name: entry, path: entryRelative });
      } else {
        files.push({
          name: entry,
          path: entryRelative,
          isImage: isImage(entry),
          sizeBytes: stat.size,
        });
      }
    }
    folders.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
    files.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
  }

  const crumbs = [];
  if (relative !== '') {
    let accum = '';
    for (const segment of relative.split('/')) {
      accum = accum === '' ? segment : `${accum}/${segment}`;
      crumbs.push({ name: segment, path: accum });
    }
  }
  let parentPath = null;
  if (relative !== '') {
    const slash = relative.lastIndexOf('/');
    parentPath = slash === -1 ? '' : relative.slice(0, slash);
  }

  res.renderView('gallery/index', {
    folders,
    files,
    crumbs,
    currentPath: relative,
    parentPath,
    isEmpty: !!dir && folders.length === 0 && files.length === 0,
  }, 'layout/base');
}

/** Streams one file's raw bytes — images render inline, everything else downloads. */
async function file(req, res) {
  const relative = String(req.query.p || '').replace(/^\/+|\/+$/g, '');
  const resolved = resolveSafePath(relative);

  if (!resolved || !fs.statSync(resolved).isFile()) {
    res.status(404).send('File not found.');
    return;
  }

  const mime = MIME_BY_EXT[path.extname(resolved).toLowerCase()] || 'application/octet-stream';
  res.set('Content-Type', mime);
  res.set('Content-Length', String(fs.statSync(resolved).size));
  res.set('Cache-Control', 'private, max-age=3600');
  if (!mime.startsWith('image/')) {
    const safeName = path.basename(resolved).split('/').join('-').split('\\').join('-');
    res.set('Content-Disposition', `attachment; filename="${safeName}"`);
  }
  fs.createReadStream(resolved).pipe(res);
}

module.exports = { index, file, resolveSafePath };
