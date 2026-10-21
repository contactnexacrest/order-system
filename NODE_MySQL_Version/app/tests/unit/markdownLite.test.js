'use strict';

const markdownLite = require('../../src/helpers/markdownLite');

/**
 * Port of MarkdownLiteTest.php. 90-item backlog Phase 5: editor-level
 * bold/emphasis control for the T&C Clause library. tokenize() is the
 * single source of truth both the markdown_lite Nunjucks filter (PDF)
 * and the DOCX builder's run styling read from.
 */
describe('markdownLite (90-item backlog Phase 5)', () => {
  it('tokenize plain text is one unstyled segment', () => {
    expect(markdownLite.tokenize('Plain clause text, nothing special.')).toEqual([
      { text: 'Plain clause text, nothing special.', bold: false, italic: false, underline: false },
    ]);
  });

  it('tokenize bold, italic and underline markers', () => {
    const segments = markdownLite.tokenize('Normal **bold** then *italic* then _underline_ done.');

    expect(segments[0].text).toBe('Normal ');
    expect(segments[0].bold).toBe(false);

    const bold = segments.find((s) => s.text === 'bold');
    const italic = segments.find((s) => s.text === 'italic');
    const underline = segments.find((s) => s.text === 'underline');

    expect(bold.bold).toBe(true);
    expect(italic.italic).toBe(true);
    expect(italic.bold).toBe(false);
    expect(underline.underline).toBe(true);
  });

  it('toHtml escapes then wraps', () => {
    expect(markdownLite.toHtml('Buyer & Seller agree that **payment** is due.'))
      .toBe('Buyer &amp; Seller agree that <strong>payment</strong> is due.');
  });

  it('toHtml handles all three markers together', () => {
    const html = markdownLite.toHtml('A **bold** word, an *italic* word, and an _underlined_ word.');

    expect(html).toBe('A <strong>bold</strong> word, an <em>italic</em> word, and an <u>underlined</u> word.');
  });

  it('unpaired delimiter is left literal', () => {
    expect(markdownLite.toHtml('Price is 5 * 3 = 15.')).toBe('Price is 5 * 3 = 15.');
  });

  it('delimiter adjacent to whitespace does not open a marker', () => {
    expect(markdownLite.toHtml('See note * below for details.')).toBe('See note * below for details.');
  });

  it('does not HTML-escape twice when input already has a literal ampersand', () => {
    expect(markdownLite.toHtml('Terms & Conditions')).toBe('Terms &amp; Conditions');
  });

  it('empty string produces no visible text', () => {
    expect(markdownLite.toHtml('')).toBe('');
  });
});
