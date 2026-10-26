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
  // Mirrors the min length enforced server-side in ReasonValidator.php.
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

  // External QA report L-38: a form's confirmation message used to be
  // built as a hand-escaped JS string literal INSIDE the onsubmit HTML
  // attribute (e.g. `onsubmit="return confirm('Delete \"name\"?');"`) —
  // a literal `\"` in that source has no escaping meaning to the HTML
  // attribute parser (backslash isn't special in HTML), so it closes
  // the attribute early the moment a name needed those decorative
  // quote marks, breaking the whole handler. Reading the message from
  // a data-confirm attribute instead sidesteps the problem entirely:
  // the browser decodes it as a plain string (ordinary HTML-attribute
  // escaping, already handled server-side by htmlspecialchars()/
  // Nunjucks' own autoescaping), which is never re-parsed as JS source
  // text, so no value anywhere needs a JS-string escape at all.
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

  // Owner request (2026-10-26): each Compliance Checklist row saves on its
  // own, without reloading the page — so choices made on other rows but not
  // yet saved stay put. The reason box only shows for "Skipped", and a
  // missing/short reason is flagged right on that row.
  function initComplianceRows() {
    document.querySelectorAll('form.compliance-row-form').forEach(function (form) {
      var status = form.querySelector('select[name="status"]');
      var reason = form.querySelector('input[name="skip_reason"]');
      var result = form.querySelector('.compliance-row-result');
      var button = form.querySelector('button[type="submit"]');
      function syncReason() {
        if (!reason || !status) return;
        var skipped = status.value === 'skipped';
        reason.style.display = skipped ? '' : 'none';
        reason.required = skipped;
        // The sitewide reason hint/error sits next to the box — hide it too.
        form.querySelectorAll('.reason-hint, .reason-error').forEach(function (el) {
          el.style.display = skipped ? '' : 'none';
        });
      }
      function show(message, isError) {
        if (!result) return;
        result.textContent = message;
        result.className = 'compliance-row-result small ' + (isError ? 'form-field-error' : 'compliance-row-saved');
      }
      if (status) status.addEventListener('change', syncReason);
      syncReason();
      form.addEventListener('submit', function (e) {
        // The page-wide pre-submit check (registered earlier) may already
        // have refused this submit — respect that and stay put.
        var refused = e.defaultPrevented;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (refused) return;
        if (status && status.value === 'skipped' && reason && reason.value.trim().length < REASON_MIN_LENGTH) {
          show('Give a reason of at least ' + REASON_MIN_LENGTH + ' characters to mark this Not Applicable.', true);
          reason.focus();
          return;
        }
        if (button) button.disabled = true;
        show('Saving…', false);
        fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          credentials: 'same-origin'
        }).then(function (res) {
          return res.json().catch(function () { return { ok: false, message: 'Could not save — please reload the page and try again.' }; });
        }).then(function (data) {
          show(data.ok ? '\u2713 ' + data.message + (data.resolved_text ? ' ' + data.resolved_text : '') : (data.message || data.error || 'Could not save.'), !data.ok);
          if (data.ok && typeof data.outstanding === 'number') {
            document.querySelectorAll('[data-compliance-total-outstanding]').forEach(function (el) { el.textContent = data.outstanding; });
          }
        }).catch(function () {
          show('Could not save — check your connection and try again.', true);
        }).then(function () {
          if (button) button.disabled = false;
        });
      }, true);
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

  function fieldError(el) {
    var value = el.value.trim();
    var name = (el.name || '').replace(/\[.*$/, '');
    var type = (el.type || '').toLowerCase();
    if (el.required && value === '' && (el.tagName === 'TEXTAREA' || type === 'text' || type === 'search' || type === 'email' || type === 'tel' || type === 'url')) {
      return 'This field is required.';
    }
    if (/reason/i.test(name) && value !== '' && value.length < REASON_MIN_LENGTH) {
      return 'Reason must be at least ' + REASON_MIN_LENGTH + ' characters (' + value.length + ' so far).';
    }
    if (type === 'text' && NUMERIC_FIELD_NAME.test(name) && value !== '' && !/^\d*\.?\d+$/.test(value)) {
      return 'Enter a number — digits and a decimal point only (no commas, spaces or units).';
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
    initRemittanceStatements();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
