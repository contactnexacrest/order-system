'use strict';

/**
 * Port of App\Helpers\MarkdownLite. 90-item backlog Phase 5: editor-level
 * bold/emphasis control for the main T&C Clause library (Terms &
 * Conditions, Legal Terms, Definitions — printed on every buyer-facing
 * document). Deliberately NOT the same stored-HTML/contenteditable
 * approach the Annexure's free-text field uses — clause_text stays plain
 * text in the database (zero migration risk to decades of existing legal
 * wording), and staff instead type a tiny markdown-like syntax directly:
 * **bold**, *italic*, _underline_. tokenize() is the single source of
 * truth both toHtml() (PDF, via the markdown_lite Nunjucks filter) and
 * the DOCX builder's run-by-run styling read from, so the two renderers
 * can never disagree about which part of a clause is bold/italic/underlined.
 *
 * Deliberately not a general-purpose Markdown/CommonMark parser: no
 * nesting, no escaping syntax for a literal asterisk, and a stray
 * unpaired delimiter is always left as a literal character rather than
 * guessed at — exactly enough for "make this phrase bold" in a legal
 * clause, nothing more.
 */

const PATTERN = /\*\*(?!\s)(.+?)(?<!\s)\*\*|\*(?!\s)(.+?)(?<!\s)\*|_(?!\s)(.+?)(?<!\s)_/gs;

function plain(text) {
  return { text, bold: false, italic: false, underline: false };
}

/**
 * @returns {Array<{text: string, bold: boolean, italic: boolean, underline: boolean}>}
 *   segments in order; concatenating every segment's `text` reproduces
 *   the original input with its bold/italic/underline markers stripped.
 */
function tokenize(text) {
  const str = String(text == null ? '' : text);
  const segments = [];
  let lastEnd = 0;
  let match;

  PATTERN.lastIndex = 0;
  while ((match = PATTERN.exec(str)) !== null) {
    if (match.index > lastEnd) {
      segments.push(plain(str.slice(lastEnd, match.index)));
    }
    if (match[1] !== undefined) {
      segments.push({ text: match[1], bold: true, italic: false, underline: false });
    } else if (match[2] !== undefined) {
      segments.push({ text: match[2], bold: false, italic: true, underline: false });
    } else if (match[3] !== undefined) {
      segments.push({ text: match[3], bold: false, italic: false, underline: true });
    }
    lastEnd = match.index + match[0].length;
  }

  if (lastEnd < str.length) {
    segments.push(plain(str.slice(lastEnd)));
  }

  return segments.length === 0 ? [plain(str)] : segments;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/** HTML-escapes every segment first, then wraps it — a literal &/</> in the clause can never become unsafe markup. */
function toHtml(text) {
  let html = '';
  for (const seg of tokenize(text)) {
    let escaped = escapeHtml(seg.text);
    if (seg.bold) {
      escaped = `<strong>${escaped}</strong>`;
    }
    if (seg.italic) {
      escaped = `<em>${escaped}</em>`;
    }
    if (seg.underline) {
      escaped = `<u>${escaped}</u>`;
    }
    html += escaped;
  }
  return html;
}

module.exports = { tokenize, toHtml };
