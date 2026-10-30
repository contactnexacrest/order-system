(function () {
  'use strict';

  function markSubmitting(form, submitter) {
    var controls = form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])');
    controls.forEach(function (btn) {
      btn.disabled = true;
    });
    if (!submitter) return;
    var label = submitter.dataset.loadingText || 'Working…';
    if (submitter.tagName === 'INPUT') {
      submitter.value = label;
    } else {
      submitter.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span> ' + label;
    }
  }

  function initSlowDownloadLinks() {
    document.querySelectorAll('a.js-slow-download').forEach(function (link) {
      link.addEventListener('click', function () {
        if (link.classList.contains('is-loading')) return;
        var original = link.innerHTML;
        link.classList.add('is-loading');
        link.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span> ' + (link.dataset.loadingText || 'Preparing…');
        var revert = function () {
          link.innerHTML = original;
          link.classList.remove('is-loading');
          window.removeEventListener('focus', revert);
        };
        window.addEventListener('focus', revert);
        setTimeout(revert, 20000);
      });
    });
  }

  function initTableScrollShadows() {
    // Wraps every table.list at runtime so a scroll-shadow hint can be shown
    // on the edge(s) that have more content off-screen — no per-view markup
    // needed, same "no per-page wiring" approach as the rest of this file.
    document.querySelectorAll('table.list').forEach(function (table) {
      if (table.closest('.table-scroll')) return;
      var wrapper = document.createElement('div');
      wrapper.className = 'table-scroll';
      table.parentNode.insertBefore(wrapper, table);
      wrapper.appendChild(table);
      var update = function () {
        var scrollable = wrapper.scrollWidth > wrapper.clientWidth + 1;
        wrapper.classList.toggle('is-scrollable', scrollable);
        wrapper.classList.toggle('at-start', wrapper.scrollLeft <= 0);
        wrapper.classList.toggle('at-end', wrapper.scrollLeft + wrapper.clientWidth >= wrapper.scrollWidth - 1);
      };
      wrapper.addEventListener('scroll', update);
      window.addEventListener('resize', update);
      update();
    });
  }

  // Sitewide "Reason" min-length hint + blur validation — every reason/
  // override_reason/resolved_reason field gets an inline "must be at
  // least N characters" hint and a red error on blur if too short, with
  // no per-page wiring needed (same approach as initTableScrollShadows).
  // Mirrors the min length enforced server-side in reasonValidator.js.
  function initReasonFieldValidation() {
    var MIN_LENGTH = 10;
    document.querySelectorAll('input[name*="reason" i]:not([type="hidden"]), textarea[name*="reason" i]').forEach(function (field) {
      var container = field.closest('label') || field.parentElement;
      var alreadyHinted = container && /\b\d+\s*characters?\b/i.test(container.textContent);
      var hint = null;
      if (!alreadyHinted) {
        hint = document.createElement('small');
        hint.className = 'reason-hint muted';
        hint.textContent = 'Must be at least ' + MIN_LENGTH + ' characters.';
        field.insertAdjacentElement('afterend', hint);
      }

      var showError = function () {
        var val = field.value.trim();
        var invalid = val.length > 0 && val.length < MIN_LENGTH;
        field.classList.toggle('field-invalid', invalid);
        var existingError = field.parentElement.querySelector('.reason-error');
        if (invalid) {
          if (hint) hint.style.display = 'none';
          if (!existingError) {
            existingError = document.createElement('small');
            existingError.className = 'reason-error';
            field.insertAdjacentElement('afterend', existingError);
          }
          existingError.textContent = 'Reason must be at least ' + MIN_LENGTH + ' characters (' + val.length + ' so far).';
        } else if (existingError) {
          existingError.remove();
          field.classList.remove('field-invalid');
          if (hint) hint.style.display = '';
        }
      };
      field.addEventListener('blur', showError);
      field.addEventListener('input', function () {
        if (field.classList.contains('field-invalid')) showError();
      });
    });
  }

  // External QA report L-39: a form's confirmation message used to be
  // built by dropping {{ name }} directly into a JS string literal
  // inside the onsubmit attribute (e.g. `onsubmit="return confirm('Delete
  // {{ name }}?');"`). Nunjucks autoescapes {{ }} for the surrounding
  // HTML-attribute context (encoding a ' as &#39;), but the browser
  // decodes that entity back to a raw ' before handing the attribute
  // value to the JS engine as source text — so any name containing an
  // apostrophe (or, for the decorative-quote variants, any name
  // containing a quote mark) still breaks the JS string the instant it's
  // decoded, regardless of the HTML-level escaping. Reading the message
  // from a data-confirm attribute instead sidesteps the problem entirely:
  // the browser decodes it as a plain string that is never re-parsed as
  // JS source text, so no value anywhere needs a JS-string escape at all.
  // Capture phase so this always runs before the bubble-phase listener
  // below, matching the inline-onsubmit ordering it replaces.
  function initConfirmDialogs() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      var message = form.dataset.confirm;
      if (message === undefined) return;
      if (!window.confirm(message)) {
        e.preventDefault();
      }
    }, true);
  }

  // Opens every collapsed <details> around an element (a step card, a
  // settings section...) so a form inside one can actually be seen.
  function revealElement(el) {
    for (var node = el.closest('details'); node; node = node.parentElement ? node.parentElement.closest('details') : null) {
      node.open = true;
    }
  }

  // Owner request (2026-10-26): a remittance is recorded together with the
  // day's exchange rate — this spells out what will be saved: "We received
  // USD 1,800.00 at today's exchange rate of ₹83.2500 = ₹149,850.00".
  // Export benefit schemes (saved all at once): a row's reason is required
  // (10+ characters) only while that row is marked Not Eligible.
  function initSchemeRows() {
    document.querySelectorAll('[data-scheme-status]').forEach(function (select) {
      var row = select.closest('tr');
      var reason = row && row.querySelector('[data-scheme-reason]');
      if (!reason) return;
      var sync = function () {
        var needed = select.value === 'not_eligible';
        reason.required = needed;
        if (needed) {
          reason.setAttribute('minlength', '10');
        } else {
          reason.removeAttribute('minlength');
        }
      };
      select.addEventListener('change', sync);
      sync();
    });
  }

  function initRemittanceStatements() {
    document.querySelectorAll('[data-remit-statement]').forEach(function (line) {
      var form = line.closest('form');
      var amountEl = form && form.querySelector('[data-remit-amount]');
      var rateEl = form && form.querySelector('[data-remit-rate]');
      if (!amountEl || !rateEl) return;
      var currency = line.getAttribute('data-currency') || '';
      function money(n, digits) {
        return n.toLocaleString('en-IN', { minimumFractionDigits: digits, maximumFractionDigits: digits });
      }
      function update() {
        var amount = parseFloat(amountEl.value);
        var rate = parseFloat(rateEl.value);
        if (!(amount > 0) || !(rate > 0)) {
          line.textContent = 'Enter the amount received and today\'s exchange rate — both are recorded together.';
          return;
        }
        line.textContent = 'We received ' + currency + ' ' + money(amount, 2) + ' at today\'s exchange rate of \u20B9' + money(rate, 4) + ' = \u20B9' + money(amount * rate, 2) + '.';
      }
      amountEl.addEventListener('input', update);
      rateEl.addEventListener('input', update);
      update();
    });
  }

  // Owner request (2026-10-26): Step 12a's single "+ Add a cost" — the type
  // picked shows its own form; the rest stay hidden.
  function initFinCostPicker() {
    document.querySelectorAll('[data-fin-cost-type]').forEach(function (select) {
      var box = select.closest('.fin-add-cost');
      if (!box) return;
      function sync() {
        box.querySelectorAll('[data-fin-cost-form]').forEach(function (form) {
          form.hidden = form.getAttribute('data-fin-cost-form') !== select.value;
        });
      }
      select.addEventListener('change', sync);
      sync();
    });
  }

  // Owner request (2026-10-26, t4): a crate's HS code is shown from the
  // product picked on that row — read-only; the server derives it again.
  function initCrateHsCodes() {
    document.querySelectorAll('[data-crate-product]').forEach(function (select) {
      var hs = select.closest('tr') && select.closest('tr').querySelector('[data-crate-hs]');
      if (!hs) return;
      select.addEventListener('change', function () {
        var opt = select.options[select.selectedIndex];
        hs.value = opt ? (opt.getAttribute('data-hs') || '') : '';
      });
    });
  }

  function initComplianceRows() {
    // Owner request (2026-10-26): the checklist saves with one button per
    // card; each row only needs its reason box shown (and required, 10+
    // characters) while it is marked Skipped.
    document.querySelectorAll('[data-compliance-row]').forEach(function (row) {
      var status = row.querySelector('[data-compliance-status]');
      var reason = row.querySelector('[data-compliance-reason]');
      if (!status || !reason) return;
      function syncReason() {
        var skipped = status.value === 'skipped';
        reason.style.display = skipped ? '' : 'none';
        reason.required = skipped;
        if (skipped) {
          reason.setAttribute('minlength', String(REASON_MIN_LENGTH));
        } else {
          reason.removeAttribute('minlength');
        }
        row.querySelectorAll('.reason-hint, .reason-error').forEach(function (el) {
          el.style.display = skipped ? '' : 'none';
        });
      }
      status.addEventListener('change', syncReason);
      syncReason();
    });
  }

  // Owner rule (2026-10-26): on the order page a sub-step can't be opened
  // until the one before it is complete. The server marks such a card
  // data-locked (CSS greys it and ignores clicks); this adds the "complete
  // Step X first" label and keeps it shut against the keyboard or any
  // script that tries to open it.
  function initLockedSteps() {
    document.querySelectorAll('details[data-locked]').forEach(function (card) {
      var summary = card.querySelector(':scope > summary');
      if (summary && !summary.querySelector('.step-locked-note')) {
        var note = document.createElement('span');
        note.className = 'step-locked-note';
        note.textContent = '\uD83D\uDD12 ' + card.getAttribute('data-locked');
        summary.appendChild(note);
        summary.setAttribute('tabindex', '-1');
        summary.setAttribute('aria-disabled', 'true');
      }
      card.open = false;
      card.addEventListener('toggle', function () {
        if (card.open) card.open = false;
      });
    });
  }

  // Owner request (2026-10-25): "if any error occurs, whatever we filled
  // earlier should not disappear". Nearly every POST handler answers a
  // rejected submission with a redirect + error flash, which used to
  // re-render the form empty. So every POST form's typed values are kept
  // in this tab's sessionStorage on submit; if the next page shows an
  // error, they're put back into the same form (matched by action) and
  // that form is revealed. Any other outcome discards them. File inputs
  // can't be refilled by a page (browser security) — the notice says so.
  // Passwords, hidden fields (CSRF tokens etc.) and forms marked
  // data-no-draft are never stored.
  var FORM_DRAFT_KEY = 'form-drafts:v1';
  var FORM_DRAFT_MAX_AGE_MS = 10 * 60 * 1000;

  function readFormDrafts() {
    try {
      return JSON.parse(window.sessionStorage.getItem(FORM_DRAFT_KEY) || '[]');
    } catch (e) {
      return [];
    }
  }

  function writeFormDrafts(drafts) {
    try {
      if (drafts.length) {
        window.sessionStorage.setItem(FORM_DRAFT_KEY, JSON.stringify(drafts));
      } else {
        window.sessionStorage.removeItem(FORM_DRAFT_KEY);
      }
    } catch (e) { /* storage unavailable — nothing to keep */ }
  }

  function formsWithAction(action) {
    return Array.prototype.filter.call(document.forms, function (f) { return f.action === action; });
  }

  function saveFormDraft(form) {
    if (form.dataset.noDraft !== undefined || form.querySelector('input[type="password"]')) return;
    var fields = [];
    var hadFile = false;
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled) return;
      var type = (el.type || '').toLowerCase();
      if (type === 'file') {
        if (el.files && el.files.length) hadFile = true;
        return;
      }
      if (['hidden', 'password', 'submit', 'button', 'reset', 'image'].indexOf(type) !== -1) return;
      if (type === 'checkbox' || type === 'radio') {
        fields.push({ name: el.name, value: el.value, checked: el.checked });
      } else if (type === 'select-multiple') {
        fields.push({ name: el.name, values: Array.prototype.filter.call(el.options, function (o) { return o.selected; }).map(function (o) { return o.value; }) });
      } else {
        fields.push({ name: el.name, value: el.value });
      }
    });
    if (!fields.length) return;
    var drafts = readFormDrafts().filter(function (d) { return d.action !== form.action; });
    drafts.push({ action: form.action, nth: formsWithAction(form.action).indexOf(form), at: Date.now(), hadFile: hadFile, fields: fields });
    writeFormDrafts(drafts);
  }

  function restoreFormDraft(form, draft) {
    var seen = {};
    draft.fields.forEach(function (f) {
      var matches = form.querySelectorAll('[name="' + f.name.replace(/(["\\])/g, '\\$1') + '"]');
      if (f.checked !== undefined) {
        Array.prototype.forEach.call(matches, function (el) {
          if (el.value === f.value) el.checked = f.checked;
        });
        return;
      }
      // Repeated names (crate rows, product lines: name="x[]") are restored
      // in order — the Nth saved value goes into the Nth field.
      var i = seen[f.name] || 0;
      seen[f.name] = i + 1;
      var el = matches[i];
      if (!el) return;
      if (f.values) {
        Array.prototype.forEach.call(el.options, function (o) { o.selected = f.values.indexOf(o.value) !== -1; });
      } else {
        el.value = f.value;
      }
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    var notice = document.createElement('div');
    notice.className = 'alert alert-info form-draft-notice';
    notice.textContent = 'Your entries have been kept — correct the problem shown at the top of the page and submit again.' +
      (draft.hadFile ? ' Attachments cannot be kept by the browser: please select the file again.' : '');
    form.insertBefore(notice, form.firstChild);
  }

  function initFormDraftRestore() {
    var drafts = readFormDrafts();
    writeFormDrafts([]);
    if (!drafts.length || !document.querySelector('.alert-error')) return;
    var firstRestored = null;
    drafts.forEach(function (draft) {
      if (Date.now() - draft.at > FORM_DRAFT_MAX_AGE_MS) return;
      var form = formsWithAction(draft.action)[draft.nth] || formsWithAction(draft.action)[0];
      if (!form) return;
      restoreFormDraft(form, draft);
      revealElement(form);
      firstRestored = firstRestored || form;
    });
    if (firstRestored && !window.location.hash) {
      firstRestored.scrollIntoView({ block: 'center' });
    }
  }

  // Checks a form before it is submitted, for what the browser's own
  // validation (required/type/min/max) misses: a required field holding
  // only spaces, a reason shorter than the server's minimum, and an
  // amount/weight/CBM/quantity/percentage/rate typed as text that isn't a
  // plain number ("1,200", "950 USD" — the server has no comma/unit
  // handling). Messages appear next to the field and the submit is
  // stopped before any confirm dialog or "Working…" state.
  var REASON_MIN_LENGTH = 10;
  // Asks for a reason in a prompt() dialog — the delete-draft / remove-
  // document / mark-lost / cancellation / unlock actions. A reason shorter
  // than the server's minimum used to be silently thrown away (the button
  // just did nothing — "delete is not working" with "duplicate", 9
  // characters); now it asks again, saying why. Cancel still cancels.
  // Returns the trimmed reason, or null if cancelled.
  window.promptForReason = function (question) {
    var message = question;
    for (;;) {
      var reason = window.prompt(message);
      if (reason === null) return null;
      reason = reason.trim();
      if (reason.length >= REASON_MIN_LENGTH) return reason;
      message = 'Please give a reason of at least ' + REASON_MIN_LENGTH + ' characters (you entered ' + reason.length + ').\n\n' + question;
    }
  };

  // Matched against numeric DB columns only — estimated_package_count is
  // deliberately free text ("TBD at packing"), so counts are listed by name.
  var NUMERIC_FIELD_NAME = /(_amount|amount_|_inr$|_kg$|_cbm$|^cbm$|_pct$|_rate$|unit_price|quantity|^crate_count$|_days$)/;

  // Owner request (2026-10-27): a "Re-enter ..." field is only worth
  // having if the page checks it. A field matches the one it confirms:
  // data-match="<name>" names it, and the conventions need no attribute —
  // "<name>_confirm" confirms "<name>", "confirm_password" confirms
  // "new_password" (or "password").
  function matchTarget(el) {
    var form = el.form;
    if (!form || !el.name) return null;
    var names = [];
    if (el.dataset.match) names.push(el.dataset.match);
    else if (/_confirm$/.test(el.name)) names.push(el.name.replace(/_confirm$/, ''));
    else if (el.name === 'confirm_password') names.push('new_password', 'password');
    for (var i = 0; i < names.length; i++) {
      var target = form.elements.namedItem(names[i]);
      if (target && target !== el && 'value' in target) return target;
    }
    return null;
  }

  function matchError(el) {
    var target = matchTarget(el);
    if (!target) return null;
    // Account numbers ignore spaces, exactly like the server; passwords must match as typed.
    var normalise = function (v) { return /account/i.test(el.name) ? v.replace(/\s+/g, '') : v; };
    var mine = normalise(el.value);
    var theirs = normalise(target.value);
    if (mine === theirs || (mine === '' && theirs === '')) return null;
    if (el.dataset.matchMessage) return el.dataset.matchMessage;
    if (/account/i.test(el.name)) return 'The two account numbers do not match — type it again.';
    if (/password/i.test(el.name)) return 'The two passwords do not match — type it again.';
    return 'This does not match the field above — type it again.';
  }

  // Bank details (supplier / logistics partner): the same rules the server
  // applies in BankDetailsInput — once an account number is typed the
  // holder, bank and IFSC are needed; other bank fields need an account
  // number (unless one is already on file); the IFSC has to look like one.
  var BANK_FIELDS = ['bank_account_name', 'bank_name', 'bank_branch', 'bank_ifsc', 'bank_account_type', 'bank_upi_id'];
  var BANK_LABELS = { bank_account_name: 'account holder name', bank_name: 'bank name', bank_ifsc: 'IFSC code' };

  function bankError(el, value) {
    var form = el.form;
    var number = form && form.elements.namedItem('bank_account_number');
    if (!number || !('value' in number)) return null;
    var typedNumber = number.value.replace(/\s+/g, '') !== '';
    if (BANK_LABELS[el.name] && typedNumber && value === '') {
      return 'Enter the ' + BANK_LABELS[el.name] + ' for the bank account.';
    }
    if (el.name === 'bank_ifsc' && value !== '' && !/^[A-Z]{4}0[A-Z0-9]{6}$/.test(value.toUpperCase())) {
      return 'Enter a valid 11-character IFSC code (e.g. SBIN0001234).';
    }
    if (el === number && !typedNumber && number.dataset.bankOnFile !== '1') {
      var anyFilled = BANK_FIELDS.some(function (n) {
        var f = form.elements.namedItem(n);
        return f && 'value' in f && f.value.trim() !== '';
      });
      if (anyFilled) return 'Enter the bank account number (twice), or leave the whole bank section empty.';
    }
    return null;
  }

  function fieldError(el) {
    var value = el.value.trim();
    var name = (el.name || '').replace(/\[.*$/, '');
    var type = (el.type || '').toLowerCase();
    if (el.required && value === '' && (el.tagName === 'TEXTAREA' || type === 'text' || type === 'search' || type === 'email' || type === 'tel' || type === 'url' || type === 'password' || type === 'number')) {
      return 'This field is required.';
    }
    var mismatch = matchError(el);
    if (mismatch) return mismatch;
    // data-required-if="<name>=<value>": required while that field has that value
    // (e.g. the staff member's name when an expense was paid by staff);
    // data-required-if="<name>": required once that field is filled (e.g. the
    // signed agreement's file once its signing date is entered).
    if (el.dataset.requiredIf && el.form && value === '') {
      var cond = el.dataset.requiredIf.split('=');
      var other = el.form.elements.namedItem(cond[0]);
      if (other && 'value' in other && (cond.length === 1 ? other.value.trim() !== '' : other.value === cond[1])) {
        return el.dataset.requiredIfMessage || 'This field is required.';
      }
    }
    // data-required-unless="<file field>": required when that file field is empty
    // (e.g. the reason, when no receipt is attached).
    if (el.dataset.requiredUnless && el.form && value === '') {
      var file = el.form.elements.namedItem(el.dataset.requiredUnless);
      if (file && file.files && file.files.length === 0) return el.dataset.requiredUnlessMessage || 'Attach the receipt / bill, or give the reason no bill exists.';
    }
    // data-not-more-than="<selector>": a number that cannot exceed another
    // field in the same form (e.g. a proforma's amount asked now, at most
    // the bill total).
    if (el.dataset.notMoreThan && el.form && value !== '') {
      var cap = el.form.querySelector(el.dataset.notMoreThan);
      if (cap && cap.value !== '' && parseFloat(value) > parseFloat(cap.value) + 0.004) {
        return el.dataset.notMoreThanMessage || 'This cannot be more than ' + cap.value + '.';
      }
    }
    // data-max-decimals="n" / data-max-value="x": a number the database can
    // hold as entered (QA Round 6, R6-N14/N15/N21/N23) — 0.0001 for a
    // 2-decimal price was silently saved as 0.00, and a too-large value was
    // a server error.
    if (value !== '' && el.dataset.maxDecimals !== undefined && /^\d*\.?\d+$/.test(value)) {
      var decimalsPart = value.indexOf('.') === -1 ? '' : value.slice(value.indexOf('.') + 1).replace(/0+$/, '');
      var maxDecimals = parseInt(el.dataset.maxDecimals, 10);
      if (decimalsPart.length > maxDecimals) return maxDecimals === 0 ? 'Enter a whole number.' : 'Use at most ' + maxDecimals + ' decimal' + (maxDecimals === 1 ? '' : 's') + '.';
    }
    if (value !== '' && el.dataset.maxValue !== undefined && parseFloat(value) > parseFloat(el.dataset.maxValue)) {
      return 'This can be at most ' + el.dataset.maxValue + '.';
    }
    // data-zero-if="<name>=<value>": must be 0 while that field has that
    // value (e.g. the GST rate on a transport bill under reverse charge —
    // the transporter charges no GST; we pay it to the government).
    if (el.dataset.zeroIf && el.form && value !== '') {
      var zeroCond = el.dataset.zeroIf.split('=');
      var zeroField = el.form.elements.namedItem(zeroCond[0]);
      if (zeroField && !zeroField.disabled && zeroField.value === zeroCond[1] && parseFloat(value) !== 0) {
        return el.dataset.zeroIfMessage || 'This must be 0 here.';
      }
    }
    // data-sum-group="<group>" data-sum-total="<n>": the fields of a group
    // must add up to n (e.g. the team incentive role shares, 100%).
    if (el.dataset.sumGroup && el.form && value !== '') {
      var sumFields = el.form.querySelectorAll('[data-sum-group="' + el.dataset.sumGroup + '"]');
      var sum = 0;
      for (var si = 0; si < sumFields.length; si++) sum += parseFloat(sumFields[si].value) || 0;
      var target = parseFloat(el.dataset.sumTotal);
      if (Math.abs(sum - target) > 0.001) return 'These must add up to ' + target + ' (now ' + Math.round(sum * 100) / 100 + ').';
    }
    var bankMessage = bankError(el, value);
    if (bankMessage) return bankMessage;
    if (/^bank_account_number(_confirm)?$/.test(name) && value !== '' && !/^[0-9]{6,20}$/.test(value.replace(/\s+/g, ''))) {
      return 'Enter the bank account number as digits only (6 to 20 digits).';
    }
    if (/reason/i.test(name) && value !== '' && value.length < REASON_MIN_LENGTH) {
      return 'Reason must be at least ' + REASON_MIN_LENGTH + ' characters (' + value.length + ' so far).';
    }
    if (type === 'text' && NUMERIC_FIELD_NAME.test(name) && value !== '' && !/^\d*\.?\d+$/.test(value)) {
      return 'Enter a number — digits and a decimal point only (no commas, spaces or units).';
    }
    // Every other built-in rule (required selects, dates and files, min /
    // max, minlength, pattern, email...) — so every form, present and
    // future, is checked before it is sent, with the message beside the field.
    if (el.willValidate && el.validity && !el.validity.valid) {
      return el.validationMessage || 'Please check this field.';
    }
    return null;
  }

  function showFieldError(el, message) {
    el.classList.add('field-invalid');
    var error = el.parentElement.querySelector('.form-field-error');
    if (!error) {
      error = document.createElement('small');
      error.className = 'reason-error form-field-error';
      el.insertAdjacentElement('afterend', error);
    }
    error.textContent = message;
    // The reason field's own blur error says the same thing — keep one.
    var reasonError = el.parentElement.querySelector('.reason-error:not(.form-field-error)');
    if (reasonError) reasonError.remove();
  }

  function clearFieldError(el) {
    el.classList.remove('field-invalid');
    var error = el.parentElement.querySelector('.form-field-error');
    if (error) error.remove();
  }

  function initSubmitValidation() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement) || form.noValidate) return;
      if (e.submitter && e.submitter.formNoValidate) return;
      var firstInvalid = null;
      Array.prototype.forEach.call(form.elements, function (el) {
        if (el.disabled || !('value' in el) || !el.name || (el.type || '').toLowerCase() === 'hidden') return;
        var message = fieldError(el);
        if (message) {
          showFieldError(el, message);
          firstInvalid = firstInvalid || el;
        } else {
          clearFieldError(el);
        }
      });
      if (!firstInvalid) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      revealElement(firstInvalid);
      firstInvalid.focus();
    }, true);
    document.addEventListener('input', function (e) {
      var el = e.target;
      if (el && el.classList && el.classList.contains('field-invalid') && !fieldError(el)) clearFieldError(el);
      // Typing in the field a "Re-enter" field confirms re-checks the confirmation once it has been filled in.
      if (el && el.form && el.name) {
        Array.prototype.forEach.call(el.form.elements, function (other) {
          if (other !== el && other.value !== '' && matchTarget(other) === el) {
            var message = fieldError(other);
            if (message) showFieldError(other, message); else clearFieldError(other);
          }
        });
      }
    });
    // A "Re-enter" field says straight away when it does not match — no need to wait for Save.
    document.addEventListener('focusout', function (e) {
      var el = e.target;
      if (!el || !el.form || el.value === '' || !matchTarget(el)) return;
      var message = fieldError(el);
      if (message) showFieldError(el, message); else clearFieldError(el);
    });
    // The browser's own check stops a submit before the submit listener
    // above runs; a field inside a closed step card cannot even show its
    // bubble, so the button just did nothing. Open the card and say why.
    document.addEventListener('invalid', function (e) {
      var el = e.target;
      if (!el || !('value' in el)) return;
      revealElement(el);
      showFieldError(el, el.validationMessage || 'Please check this field.');
    }, true);
  }

  // data-show-when="<name>=<value>": shown only while that field of the same
  // form has that value (e.g. a proforma's "amount asked now" only for a
  // Proforma). While hidden its inputs are disabled, so they are neither
  // checked nor sent.
  function initShowWhen() {
    function update(form) {
      Array.prototype.forEach.call(form.querySelectorAll('[data-show-when]'), function (el) {
        var cond = el.dataset.showWhen.split('=');
        var field = form.elements.namedItem(cond[0]);
        var show = !!field && field.value === cond[1];
        el.hidden = !show;
        el.style.display = show ? '' : 'none';
        Array.prototype.forEach.call(el.querySelectorAll('input, select, textarea'), function (input) {
          input.disabled = !show;
        });
      });
    }
    document.addEventListener('change', function (e) {
      if (e.target && e.target.form) update(e.target.form);
    });
    Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
      if (form.querySelector('[data-show-when]')) update(form);
    });
  }

  // Owner request (2026-10-27): GST is always picked as a rate (%) and the
  // amount worked out, never typed. Any form holding a [data-gst-base]
  // value and a [data-gst-rate] select fills its read-only
  // [data-gst-amount] / [data-gst-total] fields as either changes (the
  // server recomputes the same figure, so this is display only).
  function initGstCalc() {
    function recalc(form) {
      var base = form.querySelector('[data-gst-base]');
      var rate = form.querySelector('[data-gst-rate]');
      if (!base || !rate) return;
      var b = Math.round((parseFloat(base.value) || 0) * 100) / 100;
      var gst = Math.round(b * (parseFloat(rate.value) || 0)) / 100;
      var amount = form.querySelector('[data-gst-amount]');
      var total = form.querySelector('[data-gst-total]');
      if (amount) amount.value = gst.toFixed(2);
      if (total) total.value = (b + gst).toFixed(2);
    }
    document.addEventListener('input', function (e) {
      var el = e.target;
      if (el && el.form && (el.hasAttribute('data-gst-base') || el.hasAttribute('data-gst-rate'))) recalc(el.form);
    });
    document.addEventListener('change', function (e) {
      var el = e.target;
      if (el && el.form && el.hasAttribute('data-gst-rate')) recalc(el.form);
    });
    Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
      if (form.querySelector('[data-gst-rate]')) recalc(form);
    });
  }

  // Owner request (2026-10-27): choosing "Not Applicable" / "Not Eligible"
  // fills the row's reason in (an <option data-auto-reason>, still
  // editable); choosing a plain "Skipped" clears a filled-in reason so one
  // has to be typed.
  function initAutoReasons() {
    document.querySelectorAll('[data-compliance-status], [data-scheme-status]').forEach(function (select) {
      var row = select.closest('tr');
      var reason = row && row.querySelector('[data-compliance-reason], [data-scheme-reason]');
      if (!reason) return;
      var autos = Array.prototype.map.call(select.options, function (o) { return o.getAttribute('data-auto-reason'); }).filter(Boolean);
      select.addEventListener('change', function () {
        var opt = select.options[select.selectedIndex];
        var auto = opt && opt.getAttribute('data-auto-reason');
        var current = reason.value.trim();
        if (auto) {
          reason.value = auto;
        } else if (autos.indexOf(current) !== -1) {
          reason.value = '';
          if (select.value === 'skipped') reason.focus();
        } else {
          return;
        }
        reason.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
  }

  // QA Round 5, R5-06: every form field has an accessible name. Fields
  // with no <label>, aria-label, aria-labelledby or title (table-row
  // inputs, search boxes, textareas under a heading) get an aria-label
  // from their placeholder, else their table column header, else their
  // field name made readable — once, sitewide, for every page.
  function hasAccessibleName(el) {
    if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title')) return true;
    if (el.closest('label')) return true;
    return !!(el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '\\"') + '"]'));
  }

  function columnHeaderFor(el) {
    var cell = el.closest('td');
    var table = cell && cell.closest('table');
    if (!table) return '';
    var headerRow = table.querySelector('thead tr') || table.querySelector('tr');
    var header = headerRow && headerRow.children[cell.cellIndex];
    return header && header !== cell ? header.textContent.trim() : '';
  }

  function readableName(name) {
    var text = String(name || '').replace(/\[[^\]]*\]/g, ' ').replace(/[_-]+/g, ' ').trim();
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
  }

  // QA Round 5, R5-10: Safari/iOS 14-15.3 ignore :has(), so app.css's
  // "current step" highlight never showed there. Mark each step box whose
  // own header carries the current/locked status icon with a class the
  // stylesheet can use everywhere (the :has() rules stay for everyone else).
  function initStepStatusClasses() {
    document.querySelectorAll('summary > .step-card-icon.current, summary > .step-card-icon.locked').forEach(function (icon) {
      var box = icon.parentElement.parentElement;
      if (box) { box.classList.add(icon.classList.contains('current') ? 'is-current' : 'is-locked'); }
    });
  }

  function initFieldLabels() {
    document.querySelectorAll('input, select, textarea').forEach(function (el) {
      var type = (el.getAttribute('type') || '').toLowerCase();
      if (type === 'hidden' || type === 'submit' || type === 'button' || type === 'reset' || type === 'image') return;
      if (hasAccessibleName(el)) return;
      var name = el.getAttribute('placeholder') || columnHeaderFor(el) || readableName(el.getAttribute('name'));
      if (name) el.setAttribute('aria-label', name);
    });
  }

  function init() {
    // Before initConfirmDialogs(): both listen in the capture phase, so
    // registering this first means an invalid form is stopped before any
    // confirm() dialog is shown for it.
    initSubmitValidation();
    initConfirmDialogs();

    // Bound on document, not per-form, so every current and future POST
    // form sitewide gets a disabled/"Working…" submit button for free —
    // no per-page wiring needed. Runs after any data-confirm dialog
    // above (capture phase fires first) or inline onsubmit handler
    // (listeners on the form itself always fire before the event
    // bubbles up here), so a cancelled confirm() leaves
    // e.defaultPrevented true and this is skipped.
    document.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      var method = (form.getAttribute('method') || 'get').toLowerCase();
      if (method !== 'post') return;
      markSubmitting(form, e.submitter);
      saveFormDraft(form);
    });

    initSlowDownloadLinks();
    initTableScrollShadows();
    initReasonFieldValidation();
    initFormDraftRestore();
    initLockedSteps();
    initComplianceRows();
    initCrateHsCodes();
    initFinCostPicker();
    initRemittanceStatements();
    initSchemeRows();
    initGstCalc();
    initShowWhen();
    initAutoReasons();
    initFieldLabels();
    initStepStatusClasses();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
