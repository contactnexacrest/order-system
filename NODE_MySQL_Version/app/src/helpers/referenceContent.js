'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');

/**
 * Port of App\Helpers\ReferenceContent — renders the Internal Reference
 * Library's admin-editable plain-text content into safe HTML. A
 * deliberately tiny format — "# "/"## " for headings, "- " for a bullet,
 * "1. " for a numbered step, blank line for a paragraph break — rather
 * than a full Markdown library, since this is an internal reference page,
 * not a document template. Escapes every line BEFORE recognizing any of
 * these markers, so admin-entered content can never inject HTML/script —
 * the marker characters themselves aren't privileged, only their position
 * at the start of an already-escaped line.
 */

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function toHtml(content) {
  const lines = String(content).split(/\r\n|\r|\n/);
  let html = '';
  let listOpen = null; // 'ul' | 'ol' | null

  const closeList = () => {
    if (listOpen !== null) {
      html += `</${listOpen}>`;
      listOpen = null;
    }
  };

  for (const rawLine of lines) {
    const trimmed = rawLine.trim();
    if (trimmed === '') {
      closeList();
      continue;
    }

    if (trimmed.startsWith('## ')) {
      closeList();
      html += `<h3>${escapeHtml(trimmed.slice(3))}</h3>`;
    } else if (trimmed.startsWith('# ')) {
      closeList();
      html += `<h2>${escapeHtml(trimmed.slice(2))}</h2>`;
    } else if (trimmed.startsWith('- ')) {
      if (listOpen !== 'ul') {
        closeList();
        html += '<ul>';
        listOpen = 'ul';
      }
      html += `<li>${escapeHtml(trimmed.slice(2))}</li>`;
    } else if (/^\d+\.\s+(.*)$/.test(trimmed)) {
      const m = trimmed.match(/^\d+\.\s+(.*)$/);
      if (listOpen !== 'ol') {
        closeList();
        html += '<ol>';
        listOpen = 'ol';
      }
      html += `<li>${escapeHtml(m[1])}</li>`;
    } else {
      closeList();
      html += `<p>${escapeHtml(trimmed)}</p>`;
    }
  }
  closeList();
  return html;
}

/**
 * {placeholder} tokens resolved from live company_settings, the same
 * convention as bl_type_instruction's {company} token — so a page like
 * the Wall Reference never goes stale relative to whatever the actual
 * configured values are right now.
 */
async function substitutePlaceholders(content) {
  const keys = [
    'master_tracking_ref_format', 'client_number_format', 'order_ref_format',
    'bl_type_instruction', 'bl_consignee_instruction',
    'quantity_shortfall_tolerance_pct', 'dispute_response_days_n', 'weekly_off_days',
  ];
  let result = content;
  for (const key of keys) {
    const value = (await companySettingsRepository.get(key)) ?? '—';
    result = result.split(`{${key}}`).join(value);
  }
  return result;
}

module.exports = { toHtml, substitutePlaceholders };
