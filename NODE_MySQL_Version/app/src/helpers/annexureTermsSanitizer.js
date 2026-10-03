'use strict';

const sanitizeHtml = require('sanitize-html');
const { parse } = require('node-html-parser');

/**
 * package.json pins sanitize-html to the EXACT version 2.17.0 (no caret) —
 * sanitize-html 2.17.3+ bumps its htmlparser2 dependency from ^8.0.0 to
 * ^10.1.0, and htmlparser2 v10+ ships as an ES module with no CJS build,
 * which breaks `require()` for every file in this codebase that
 * transitively imports this module (confirmed: it took down 39 of 75 Jest
 * suites when first tried). 2.17.0-2.17.6 carry a known moderate CVE
 * (GHSA-vccv-cmxp-4j9h and two ApostropheCMS mutation-XSS advisories), but
 * none apply here: that CVE is about javascript: URIs leaking through
 * action/formaction/data/poster/background attributes, and the other two
 * are about <svg>/SMIL and a `</textarea/>` parser-confusion bypass — this
 * module's allowlist (below) never permits <form>, <button>, <svg>,
 * <textarea>, <video>, <object>, <embed>, or any of those four attributes
 * on anything, so there is no path to the vulnerable code. Re-evaluate
 * this pin if the allowlist below is ever loosened, or once a CJS-built
 * htmlparser2 successor exists.
 *
 * Port of App\Services\AnnexureTermsSanitizer. Locks down the "Additional
 * Terms" WYSIWYG content (order_annexure_terms.content_html) to a small
 * allowlist of formatting tags/attributes before it's ever written to the
 * DB, and again every time it's read back for document generation
 * (documentDataAssembler emits it unescaped — |safe in Nunjucks — into
 * every generated PDF, and docxDocumentBuilder's HTML-to-docx converter
 * parses it directly into a DOCX, so this is the one and only barrier
 * between a stored <script>/onerror= payload and it actually executing in
 * whatever renders the output). Re-sanitizing on read as well as on save
 * means a row written before a stricter config ships, or inserted by
 * anything other than updateTerms(), still comes out clean.
 *
 * <img> is restricted to data: URIs only (same reasoning as
 * documentDataAssembler.js's annexureProductsBlock() — Puppeteer's PDF
 * render can't be made to depend on a live, authenticated HTTP fetch of
 * its own app mid-render) which also rules out tracking-pixel-style
 * remote images. <video>/<audio> are not in the allowlist at all: neither
 * a PDF nor a DOCX can play embedded media, so there is nothing safe to
 * keep.
 *
 * sanitize-html's allowedSchemesByTag only validates a src/href that
 * actually parses as having a scheme (or is protocol-relative, guarded
 * separately by allowProtocolRelative:false below) — a bare relative-
 * looking value like `src="x"` or `src="/some/path"` has no scheme at
 * all, so it sails through the allowlist untouched (confirmed directly
 * against this exact sanitize-html version). That's harmless for the DOCX
 * path (annexureTermsDocx's decodeDataUri requires a literal `data:`
 * prefix and silently drops anything else) but would leave a broken or
 * SSRF-shaped <img src> sitting in the stored HTML, so a second pass
 * below removes any <img> whose src isn't a data:image/ URI — the same
 * belt-and-suspenders the PHP port needs for a different reason (there,
 * HTMLPurifier's URI.AllowedSchemes is global across tags; here, it's
 * this relative-URL carve-out).
 */
const ALLOWED_TAGS = [
  'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
  'h3', 'h4', 'blockquote',
  'ul', 'ol', 'li',
  'table', 'thead', 'tbody', 'tr', 'td', 'th',
  'span', 'a', 'img',
];

const COLOR_PATTERN = [/^#[0-9a-fA-F]{3,6}$/, /^[a-zA-Z]+$/, /^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/];

const OPTIONS = {
  allowedTags: ALLOWED_TAGS,
  allowedAttributes: {
    span: ['style'],
    a: ['href', 'target', 'rel'],
    img: ['src', 'alt', 'width', 'height'],
  },
  allowedSchemesByTag: {
    a: ['http', 'https', 'mailto'],
    img: ['data'],
  },
  allowProtocolRelative: false,
  allowedStyles: {
    span: {
      color: COLOR_PATTERN,
      'background-color': COLOR_PATTERN,
      'font-weight': [/^.*$/],
      'font-style': [/^.*$/],
      'text-decoration': [/^.*$/],
    },
  },
  transformTags: {
    a: sanitizeHtml.simpleTransform('a', { target: '_blank', rel: 'nofollow noreferrer noopener' }),
  },
  // Drop an <img> sanitize-html has already stripped src from (a disallowed
  // scheme) rather than leave a stray empty tag behind.
  exclusiveFilter: (frame) => frame.tag === 'img' && !frame.attribs.src,
};

function sanitize(html) {
  const purified = sanitizeHtml(html || '', OPTIONS);
  return stripNonDataImages(purified).trim();
}

function stripNonDataImages(html) {
  if (!html.includes('<img')) return html;
  const root = parse(html);
  root.querySelectorAll('img').forEach((img) => {
    const src = img.getAttribute('src') || '';
    if (src.indexOf('data:image/') !== 0) {
      img.remove();
    }
  });
  return root.toString();
}

module.exports = { sanitize };
