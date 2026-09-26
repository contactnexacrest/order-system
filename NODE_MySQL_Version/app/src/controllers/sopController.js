'use strict';

const fs = require('fs');
const path = require('path');
const { marked } = require('marked');

/**
 * Port of App\Controllers\SopController — staff-facing viewer for the
 * Standard Operating Procedure. Reads docs/SOP/*.md directly off disk
 * (never copied into a database), so a new chapter dropped into that
 * folder shows up immediately with no code change. Deliberately not the
 * Internal Reference Library (referenceDocController): that one only
 * supports a tiny hand-rolled text format with no inline images, which
 * would strip out the very screenshots the SOP is built around and
 * require someone to manually re-paste every chapter here whenever the
 * source file changes.
 *
 * Every route here is read-only and requires nothing more than being
 * logged in (see server.js) — same visibility as the Reference Library,
 * since any staff member should be able to look this up.
 */

const DOCS_DIR = path.join(__dirname, '..', '..', '..', 'docs', 'SOP');
const IMAGES_DIR = path.join(DOCS_DIR, 'images');

marked.setOptions({ gfm: true });

/** Rewrites the SOP's own relative links/images to routes this controller serves. */
function rewriteRelativeLinks(markdown) {
  let out = markdown.split('](./images/').join('](/sop-assets/');
  out = out.replace(/\]\(\.\/([\w-]+)\.md(#[\w-]*)?\)/g, '](/sop/$1$2)');
  return out;
}

function renderMarkdownFile(filePath) {
  const raw = fs.readFileSync(filePath, 'utf8');
  return marked.parse(rewriteRelativeLinks(raw));
}

function extractTitle(filePath) {
  const raw = fs.readFileSync(filePath, 'utf8');
  const lines = raw.split(/\r\n|\r|\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (trimmed.startsWith('# ')) {
      return trimmed.slice(2).trim();
    }
  }
  return null;
}

/**
 * @returns {{slug: string, title: string, category: string}[]} every
 * chapter file present right now, in filename order (00-, 01-, 02- were
 * chosen to sort). A `ca-` prefix (sorts after every numbered chapter,
 * since digits sort before letters) marks a chapter as belonging to the
 * CA / Accounting module rather than the main order-pipeline SOP — see
 * the standing rule in this repo's history: every new module ships with
 * its own SOP chapter(s), grouped under their own heading.
 */
function chapters() {
  const files = fs.readdirSync(DOCS_DIR)
    .filter((f) => f.endsWith('.md') && f !== 'README.md')
    .sort();
  return files.map((f) => {
    const slug = f.slice(0, -3);
    const title = extractTitle(path.join(DOCS_DIR, f)) || slug;
    const category = slug.startsWith('ca-') ? 'ca' : 'main';
    return { slug, title, category };
  });
}

async function index(req, res) {
  const readmePath = path.join(DOCS_DIR, 'README.md');
  const contentHtml = fs.existsSync(readmePath) ? renderMarkdownFile(readmePath) : null;
  res.renderView('sop/index', { contentHtml, chapters: chapters() }, 'layout/base');
}

async function show(req, res) {
  const slug = path.basename(String(req.params.chapter || ''));
  const filePath = path.join(DOCS_DIR, `${slug}.md`);

  // Whitelist by real existence in the docs directory, not just a
  // path-traversal check on the string — belt and braces, since
  // path.basename() already rules out '../' but this also rejects any
  // name that simply isn't a real chapter file.
  const resolved = fs.existsSync(filePath) ? fs.realpathSync(filePath) : null;
  if (!slug || slug === 'README' || !resolved || path.dirname(resolved) !== fs.realpathSync(DOCS_DIR)) {
    res.status(404).send('SOP chapter not found.');
    return;
  }

  res.renderView('sop/show', {
    contentHtml: renderMarkdownFile(filePath),
    chapters: chapters(),
    currentSlug: slug,
  }, 'layout/base');
}

const MIME_BY_EXT = {
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
};

/** Serves an SOP screenshot — kept behind requireAuth like everything else here, never a public static file. */
async function asset(req, res) {
  const filename = path.basename(String(req.params.file || ''));
  const filePath = path.join(IMAGES_DIR, filename);
  const resolved = fs.existsSync(filePath) ? fs.realpathSync(filePath) : null;

  if (!filename || !resolved || path.dirname(resolved) !== fs.realpathSync(IMAGES_DIR)) {
    res.status(404).send('Image not found.');
    return;
  }

  const mime = MIME_BY_EXT[path.extname(filePath).toLowerCase()] || 'application/octet-stream';
  res.set('Content-Type', mime);
  res.set('Cache-Control', 'private, max-age=3600');
  fs.createReadStream(filePath).pipe(res);
}

module.exports = { index, show, asset };
