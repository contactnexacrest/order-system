'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Owner rule (2026-11-01): every input form must have placeholders. Every
 * typed input (text, email, tel, number, search, url, password) and every
 * textarea, existing or new, shows a placeholder with an example or the
 * expected format. Not on date, file, checkbox, radio, hidden, button or
 * read-only display fields. Port of FormPlaceholderTest.php.
 */
const VIEWS = path.join(__dirname, '../../views');
const NOT_TYPED = new Set(['hidden', 'checkbox', 'radio', 'file', 'date', 'datetime-local', 'time', 'month', 'week',
  'submit', 'button', 'reset', 'image', 'color', 'range']);

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((d) => (d.isDirectory() ? walk(path.join(dir, d.name)) : [path.join(dir, d.name)]));
}

/** Each opening <input>/<textarea> tag, reading past Nunjucks {{ }} / {% %} blocks that contain ">". */
function tags(text) {
  const out = [];
  for (const m of text.matchAll(/<(input|textarea)\b/g)) {
    let j = m.index + m[0].length;
    while (j < text.length) {
      if (text.startsWith('{{', j) || text.startsWith('{%', j)) {
        const k = text.indexOf(text[j + 1] === '{' ? '}}' : '%}', j);
        j = k < 0 ? text.length : k + 2;
        continue;
      }
      if (text[j] === '>') break;
      j++;
    }
    out.push([m.index, text.slice(m.index, j + 1)]);
  }
  return out;
}

describe('form placeholders (owner rule 2026-11-01)', () => {
  test('every typed input and textarea has a placeholder', () => {
    const missing = [];
    for (const file of walk(VIEWS).filter((f) => f.endsWith('.njk'))) {
      const text = fs.readFileSync(file, 'utf8');
      for (const [offset, tag] of tags(text)) {
        const t = tag.match(/\btype\s*=\s*["']([a-zA-Z-]+)["']/);
        const type = t ? t[1].toLowerCase() : 'text';
        if (tag.startsWith('<input') && NOT_TYPED.has(type)) continue;
        // QA UI-012: a placeholder or readonly only inside a {{ … if … }} / {% if %} does not count
        // (Settings showed one only for number settings), and a type chosen in the template
        // counts as typed when it can be one of the typed kinds.
        const fixed = tag.replace(/\{%-?\s*if\b[\s\S]*?\{%-?\s*endif\s*-?%\}/g, '').replace(/\{\{[\s\S]*?\}\}|\{%[\s\S]*?%\}|\{#[\s\S]*?#\}/g, '');
        const dynamicType = tag.match(/\btype\s*=\s*["'][^"']*\{\{([\s\S]*?)\}\}/);
        if (dynamicType && !/'(?:text|number|email|tel|search|url|password)'/.test(dynamicType[1])) continue;
        if (/\breadonly\b/.test(fixed) || /\splaceholder\s*=/.test(fixed)) continue;
        missing.push(`${path.relative(VIEWS, file)}:${text.slice(0, offset).split('\n').length}`);
      }
    }
    expect(missing).toEqual([]);
  });
});
