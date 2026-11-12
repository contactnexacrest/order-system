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
    document.querySelectorAll('input[name*="reason" i]:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]), textarea[name*="reason" i]').forEach(function (field) {
      var container = field.closest('label') || field.parentElement;
      var alreadyHinted = container && /\b\d+\s*characters?\b/i.test(container.textContent);
      // D-269: data-reason-hint="on-error" (compact inline boxes, e.g. Email
      // Send Approvals) shows no hint up front — only the error below.
      var hintUpFront = field.dataset.reasonHint !== 'on-error';
      var hint = null;
      if (!alreadyHinted && hintUpFront) {
        hint = document.createElement('small');
        hint.className = 'reason-hint muted';
        hint.textContent = 'Must be at least ' + MIN_LENGTH + ' characters.';
        field.insertAdjacentElement('afterend', hint);
      }

      var showError = function () {
        // QA Round 7, R7-N10: the submit check's own message (.form-field-error,
        // e.g. "Please fill out this field.") stays until the field is valid —
        // this handler only manages its own length message.
        if (field.parentElement.querySelector('.form-field-error') && fieldError(field)) return;
        var val = field.value.trim();
        var invalid = val.length > 0 && val.length < MIN_LENGTH;
        field.classList.toggle('field-invalid', invalid);
        var existingError = field.parentElement.querySelector('.reason-error:not(.form-field-error)');
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
  // QA UI-4: a report opened for one division keeps that division on its
  // own "Export CSV" links and when its date form is run again (the shared
  // Division filter above the report sets ?division= for this page only).
  function initKeepReportDivision() {
    if (location.pathname.indexOf('/reports') !== 0) return;
    var division = new URLSearchParams(location.search).get('division');
    if (!division) return;
    var links = document.querySelectorAll('main a[href^="/reports"]');
    for (var i = 0; i < links.length; i++) {
      var url = new URL(links[i].getAttribute('href'), location.origin);
      if (!url.searchParams.has('division')) {
        url.searchParams.set('division', division);
        links[i].setAttribute('href', url.pathname + url.search + url.hash);
      }
    }
    var forms = document.querySelectorAll('main form');
    for (var j = 0; j < forms.length; j++) {
      var form = forms[j];
      if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') continue;
      var action = form.getAttribute('action') || location.pathname;
      if (action.indexOf('/reports') !== 0 || form.elements.namedItem('division')) continue;
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'division';
      hidden.value = division;
      form.appendChild(hidden);
    }
  }

  // QA UI-11: "Switch to board view" / "Switch to list view" keeps the
  // filters and search the user has set (not the page number).
  function initSwitchViewKeepsFilters() {
    var current = new URLSearchParams(location.search);
    current.delete('view');
    current.delete('page');
    if (!current.toString()) return;
    var links = document.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      var text = (links[i].textContent || '').trim();
      if (text !== 'Switch to board view' && text !== 'Switch to list view') continue;
      var url = new URL(links[i].getAttribute('href'), location.origin);
      current.forEach(function (value, key) {
        if (!url.searchParams.has(key)) url.searchParams.set(key, value);
      });
      links[i].setAttribute('href', url.pathname + url.search);
    }
  }

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

  // Owner decision (2 Nov 2026, "18 yes"): a buyer remittance that differs
  // from what is still due says why. Follow-up the same day: the reason comes
  // from a list Admin keeps (each reason treated as bank charges, buyer paid
  // less or buyer paid more), or "Other — type the reason". Only the reasons
  // that fit the amount typed are offered (less arrived → bank charges or
  // short; more → excess); the Reason becomes required when the difference
  // needs one. This also fills the bank charges with the difference and
  // spells out what will be saved, e.g. "Due 5,000.20 · Received 4,980.20 ·
  // Difference 20.00 → recorded as bank charges ₹1,812.00 (cost) — advance
  // counted as fully received".
  function initRemittanceDifference() {
    document.querySelectorAll('[data-remit-diff]').forEach(function (box) {
      var form = box.closest('form');
      var amountEl = form && form.querySelector('[data-remit-amount]');
      var chargesEl = box.querySelector('[data-remit-charges]');
      var chargesWrap = box.querySelector('[data-remit-charges-wrap]');
      var reasonEl = box.querySelector('[data-remit-reason]');
      var treatmentEl = box.querySelector('[data-remit-treatment]');
      var preview = box.querySelector('[data-remit-diff-preview]');
      if (!form || !amountEl || !preview || !reasonEl) return;
      var noneOption = reasonEl.querySelector('option[data-none]');
      var remaining = parseFloat(box.getAttribute('data-remaining')) || 0;
      var due = parseFloat(box.getAttribute('data-due')) || 0;
      var soFar = Math.round((due - remaining) * 100) / 100;
      var maxPct = parseFloat(box.getAttribute('data-max-pct')) || 3;
      var currency = box.getAttribute('data-currency') || '';
      var label = box.getAttribute('data-label') || 'payment';
      function money(n) {
        return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
      function chosenOption() {
        return reasonEl.options[reasonEl.selectedIndex] || null;
      }
      function treatment() {
        var opt = chosenOption();
        var t = opt ? (opt.getAttribute('data-treatment') || '') : '';
        if (t === 'other') return treatmentEl ? treatmentEl.value : '';
        return t;
      }
      function allowedFor(diff) {
        if (diff > 0.005) return { bank_charges: true, short: true };
        if (diff < -0.005) return { excess: true };
        return {};
      }
      function filterOptions(diff) {
        var allowed = allowedFor(diff);
        Array.prototype.forEach.call(reasonEl.querySelectorAll('optgroup[data-treatment-group]'), function (group) {
          var ok = !!allowed[group.getAttribute('data-treatment-group')];
          group.hidden = !ok;
          group.style.display = ok ? '' : 'none';
          Array.prototype.forEach.call(group.querySelectorAll('option'), function (o) { o.disabled = !ok; o.hidden = !ok; });
        });
        var other = reasonEl.querySelector('option[value="other"]');
        if (other) { var anyAllowed = Object.keys(allowed).length > 0; other.disabled = !anyAllowed; other.hidden = !anyAllowed; }
        var opt = chosenOption();
        if (opt && opt.disabled) { reasonEl.value = ''; }
        if (treatmentEl) {
          Array.prototype.forEach.call(treatmentEl.options, function (o) {
            if (!o.value) return;
            o.disabled = !allowed[o.value];
            o.hidden = !allowed[o.value];
          });
          if (treatmentEl.value && !allowed[treatmentEl.value]) treatmentEl.value = '';
        }
        var needs = (soFar <= 0.005 && Math.abs(diff) > Math.max(1, due * 0.01)) || diff < -0.005;
        reasonEl.required = needs;
        if (noneOption) {
          noneOption.textContent = needs ? 'Choose…' : 'Amount as due — no difference';
        }
      }
      function showCharges(on) {
        if (!chargesWrap || !chargesEl) return;
        chargesWrap.hidden = !on;
        chargesWrap.style.display = on ? '' : 'none';
        chargesEl.disabled = !on;
      }
      function update() {
        var amount = parseFloat(amountEl.value);
        var rate = remitRateOf(form);
        if (!(amount > 0) || !(remaining > 0)) {
          preview.textContent = '';
          filterOptions(0);
          showCharges(false);
          return;
        }
        var diff = Math.round((remaining - amount) * 100) / 100;
        filterOptions(diff);
        var t = treatment();
        showCharges(t === 'bank_charges' && diff > 0);
        var head = 'Due ' + currency + ' ' + money(remaining) + ' · Received ' + currency + ' ' + money(amount) + ' · Difference ' + currency + ' ' + money(Math.abs(diff));
        if (chargesEl && t === 'bank_charges' && !chargesEl.value && diff > 0) {
          chargesEl.value = diff.toFixed(2);
        }
        var tail;
        if (Math.abs(diff) < 0.005) {
          tail = ' → nothing different: the ' + label + ' is received in full.';
        } else if (t === 'bank_charges') {
          var charges = chargesEl ? parseFloat(chargesEl.value) : diff;
          if (!(charges > 0)) charges = diff;
          tail = ' → recorded as bank charges ' + (rate > 0 ? '₹' + money(charges * rate) : currency + ' ' + money(charges)) + ' (cost) — ' + label + ' counted as fully received';
          if (charges > due * maxPct / 100 + 0.005) {
            tail += '. More than ' + maxPct + '% of the amount due — if the buyer paid less, choose a "Buyer paid less" reason instead.';
          }
        } else if (t === 'short') {
          tail = ' → short by ' + currency + ' ' + money(diff) + '; record the rest when it arrives, or a manager clears it short with a reason.';
        } else if (t === 'excess') {
          tail = ' → the buyer paid ' + currency + ' ' + money(-diff) + ' more.';
        } else {
          tail = ' → choose why it is different' + (diff > 0 ? ' (bank charges deducted, or the buyer paid less).' : ' (the buyer paid more).');
        }
        preview.textContent = head + tail;
      }
      form.addEventListener('input', update);
      form.addEventListener('change', update);
      update();
    });
  }

  // D-244 (owner, 4 Nov 2026): the rate a remittance form will save — worked
  // out from the INR the bank credited (₹ ÷ amount), or the rate typed when
  // "INR not known yet" is ticked. NaN while it cannot be worked out yet.
  function remitRateOf(form) {
    var box = form.querySelector('[data-remit-inr]');
    if (!box) {
      var rateEl = form.querySelector('[data-remit-rate]');
      return rateEl ? parseFloat(rateEl.value) : NaN;
    }
    var unknown = box.querySelector('[data-remit-inr-unknown]');
    if (unknown && unknown.checked) {
      var typed = box.querySelector('[data-remit-rate-typed]');
      return typed ? parseFloat(typed.value) : NaN;
    }
    var amountEl = form.querySelector('[data-remit-amount]');
    var inrEl = box.querySelector('[data-remit-inr-credited]');
    var amount = amountEl ? parseFloat(amountEl.value) : NaN;
    var inr = inrEl ? parseFloat(inrEl.value) : NaN;
    return amount > 0 && inr > 0 ? inr / amount : NaN;
  }

  function initRemittanceStatements() {
    document.querySelectorAll('[data-remit-statement]').forEach(function (line) {
      var form = line.closest('form');
      var amountEl = form && form.querySelector('[data-remit-amount]');
      if (!amountEl) return;
      var currency = line.getAttribute('data-currency') || '';
      function money(n, digits) {
        return n.toLocaleString('en-IN', { minimumFractionDigits: digits, maximumFractionDigits: digits });
      }
      function update() {
        var amount = parseFloat(amountEl.value);
        var rate = remitRateOf(form);
        var unknown = form.querySelector('[data-remit-inr-unknown]');
        if (!(amount > 0) || !(rate > 0)) {
          line.textContent = unknown && unknown.checked
            ? 'Enter the amount received and the exchange rate — the INR credited is entered later.'
            : 'Enter the amount received and the INR credited by the bank — the exchange rate is worked out from them.';
          return;
        }
        if (unknown && unknown.checked) {
          line.textContent = 'We received ' + currency + ' ' + money(amount, 2) + ' at the exchange rate of ₹' + money(rate, 4) + ' ≈ ₹' + money(amount * rate, 2) + ' (an estimate until the INR credited is known).';
          return;
        }
        line.textContent = 'We received ' + currency + ' ' + money(amount, 2) + '; ₹' + money(amount * rate, 2) + ' credited by the bank — exchange rate worked out ₹' + money(rate, 4) + '.';
      }
      form.addEventListener('input', update);
      form.addEventListener('change', update);
      update();
    });
  }

  // D-244 (owner, 4 Nov 2026): a buyer remittance is recorded with the INR
  // the bank credited. The rate is worked out from it and shown live. When
  // the bank's rate on the advice is typed and amount × that rate differs
  // from the INR by more than ₹1, the form asks why (the bank deducted its
  // charges / a figure was typed wrong / another reason) and will not save
  // without an answer; "I typed something wrong" keeps it from saving and
  // takes you to the INR. "INR not known yet" brings back the typed rate.
  function initRemittanceInr() {
    document.querySelectorAll('[data-remit-inr]').forEach(function (box) {
      var form = box.closest('form');
      var amountEl = form && form.querySelector('[data-remit-amount]');
      var knownBox = box.querySelector('[data-remit-inr-known]');
      var inrEl = box.querySelector('[data-remit-inr-credited]');
      var bankEl = box.querySelector('[data-remit-bank-rate]');
      var derivedEl = box.querySelector('[data-remit-rate-derived]');
      var gapBox = box.querySelector('[data-remit-gap]');
      var gapText = box.querySelector('[data-remit-gap-text]');
      var choiceEl = box.querySelector('[data-remit-gap-choice]');
      var reasonWrap = box.querySelector('[data-remit-gap-reason-wrap]');
      var reasonEl = reasonWrap && reasonWrap.querySelector('input');
      var unknownEl = box.querySelector('[data-remit-inr-unknown]');
      var typedWrap = box.querySelector('[data-remit-rate-typed-wrap]');
      var typedEl = box.querySelector('[data-remit-rate-typed]');
      if (!form || !amountEl || !inrEl || !bankEl || !choiceEl || !unknownEl || !typedEl) return;
      var currency = box.getAttribute('data-currency') || '';
      var tolerance = parseFloat(box.getAttribute('data-tolerance')) || 1;
      function show(el, on) {
        if (!el) return;
        el.hidden = !on;
        el.style.display = on ? '' : 'none';
      }
      function money(n, digits) {
        return n.toLocaleString('en-IN', { minimumFractionDigits: digits, maximumFractionDigits: digits });
      }
      function update() {
        var unknown = unknownEl.checked;
        show(knownBox, !unknown);
        show(typedWrap, unknown);
        typedEl.disabled = !unknown;
        inrEl.disabled = unknown;
        bankEl.disabled = unknown;
        var amount = parseFloat(amountEl.value);
        var inr = parseFloat(inrEl.value);
        var bank = parseFloat(bankEl.value);
        derivedEl.textContent = !unknown && amount > 0 && inr > 0 ? '₹' + money(inr / amount, 4) : '—';
        var gap = !unknown && amount > 0 && inr > 0 && bank > 0 ? Math.round((amount * bank - inr) * 100) / 100 : 0;
        var needs = Math.abs(gap) > tolerance;
        show(gapBox, needs);
        choiceEl.disabled = !needs;
        var chargesOption = choiceEl.querySelector('option[value="bank_charges"]');
        if (chargesOption) {
          chargesOption.disabled = gap < 0;
          chargesOption.hidden = gap < 0;
          if (gap < 0 && choiceEl.value === 'bank_charges') choiceEl.value = '';
        }
        if (needs && gapText) {
          gapText.textContent = 'At the bank\'s rate of ₹' + money(bank, 4) + ', ' + currency + ' ' + money(amount, 2) + ' is ₹' + money(amount * bank, 2)
            + ', but ₹' + money(inr, 2) + ' was credited — ₹' + money(Math.abs(gap), 2) + (gap > 0 ? ' less' : ' more') + '. More than ₹' + money(tolerance, 0) + ' apart, so say why.'
            + (choiceEl.value === 'bank_charges' ? ' ₹' + money(gap, 2) + ' will be recorded as an order cost "Bank Charges".' : '');
        }
        var other = needs && choiceEl.value === 'other';
        show(reasonWrap, other);
        if (reasonEl) reasonEl.disabled = !other;
        if (inrEl.classList.contains('field-invalid') && !fieldError(inrEl)) clearFieldError(inrEl);
      }
      choiceEl.addEventListener('change', function () {
        if (choiceEl.value === 'typo') {
          inrEl.focus();
          if (inrEl.select) inrEl.select();
        }
      });
      form.addEventListener('input', update);
      form.addEventListener('change', update);
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

  // D-270 (owner point 25): the kind of a unit for converting a line price.
  var UNIT_KINDS = {
    CBM: ['CBM', 'M3', 'M³', 'CUM', 'CU M'], M2: ['M2', 'M²', 'SQM', 'SQ M', 'SQMT'], SQFT: ['SQFT', 'SQ FT', 'SFT', 'FT2'],
    PCS: ['PCS', 'PC', 'PIECE', 'PIECES', 'NOS', 'NO'], RMT: ['RMT', 'RM', 'LM'], TON: ['TON', 'TONS', 'TONNE', 'MT', 'T'], KG: ['KG', 'KGS']
  };
  var UNIT_LABELS = { CBM: 'CBM', M2: 'm²', SQFT: 'sq ft', PCS: 'piece', RMT: 'running metre', TON: 'Ton', KG: 'kg' };
  var SIZE_NEEDS = { M2: ['thickness'], SQFT: ['thickness'], PCS: ['length', 'width', 'thickness'], RMT: ['width', 'thickness'] };
  function unitKind(u) {
    u = String(u || '').trim().toUpperCase();
    for (var k in UNIT_KINDS) { if (UNIT_KINDS[k].indexOf(u) !== -1) return k; }
    return null;
  }
  function pieceSizeNeed(el) {
    var scope = el.closest('.product-row') || el.form;
    if (!scope) return null;
    var picker = scope.querySelector('select.product-picker');
    var opt = picker && picker.options[picker.selectedIndex];
    if (!opt || !opt.value) return null;
    var lineType = scope.querySelector('[name="line_type"]');
    if (lineType && lineType.value !== 'goods') return null;
    var unitField = scope.querySelector('[name="unit"]') || scope.querySelector('[name="product_unit[]"]');
    var lineKind = unitKind(unitField ? unitField.value : '');
    var thick = scope.querySelector('.spec-options option[data-cbm="1"]:checked');
    var refKind = thick ? 'CBM' : unitKind(opt.dataset.unit);
    var lineRaw = String(unitField ? unitField.value : '').trim().toUpperCase();
    if (!lineKind || !refKind || lineKind === refKind || lineRaw === String(opt.dataset.unit || '').trim().toUpperCase()) return null;
    if ((lineKind === 'M2' || lineKind === 'SQFT') && (refKind === 'M2' || refKind === 'SQFT')) return null;
    var needs = (SIZE_NEEDS[lineKind] || []).concat(SIZE_NEEDS[refKind] || []);
    var size = el.dataset.pieceSize;
    if (needs.indexOf(size) === -1) return null;
    if (size === 'thickness' && scope.querySelector('.spec-options option[data-mm]:checked')) return null;
    return 'Enter the ' + size + ' — needed to compare a price per ' + UNIT_LABELS[lineKind] + '.';
  }

  // Owner decision D-336 (12 Nov 2026): an order line "As per annexure" — the sizes go
  // in the annexure. The tick box (data-annexure-line) and "Qty To Be Confirmed" are
  // never both ticked; while it is ticked Length / Width / Thickness show "As per
  // annexure" and accept nothing, and the Qty is required. The server checks the same.
  // D-338 (1a): the same on a reorder request line — one form holds several lines, so a
  // line is the nearest [data-annexure-scope] (else the form); its quantity-not-decided
  // box is [data-annexure-tbc], its quantity [data-annexure-qty] and its sizes
  // [data-piece-size] / [data-annexure-size]. One listener on the page, so a line added
  // later (reorder "+ Add product line") works too.
  function annexureScope(el) {
    return el.closest('[data-annexure-scope]') || el.form;
  }
  function annexureTbc(scope) {
    return scope.querySelector('input[type="checkbox"][data-annexure-tbc]') || scope.querySelector('input[type="checkbox"][name="quantity_is_tbc"]');
  }
  function annexureApply(box) {
    var scope = annexureScope(box);
    if (!scope) return;
    var qty = scope.querySelector('[data-annexure-qty]') || scope.querySelector('[name="quantity"]');
    var on = box.checked;
    scope.querySelectorAll('[data-piece-size], [data-annexure-size]').forEach(function (f) {
      if (on) {
        if (f.dataset.placeholderWas === undefined) f.dataset.placeholderWas = f.getAttribute('placeholder') || '';
        f.value = '';
        f.placeholder = 'As per annexure';
      } else if (f.dataset.placeholderWas !== undefined) {
        f.placeholder = f.dataset.placeholderWas;
      }
      f.disabled = on;
    });
    if (qty) {
      if (qty.dataset.requiredWas === undefined) qty.dataset.requiredWas = qty.required ? '1' : '';
      qty.required = on || qty.dataset.requiredWas === '1';
    }
  }
  function initAnnexureLine() {
    document.querySelectorAll('input[type="checkbox"][data-annexure-line]').forEach(annexureApply);
    document.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || t.type !== 'checkbox') return;
      var scope = annexureScope(t);
      if (!scope) return;
      if (t.hasAttribute('data-annexure-line')) {
        var tbc = annexureTbc(scope);
        if (t.checked && tbc) tbc.checked = false;
        annexureApply(t);
        return;
      }
      var box = scope.querySelector('input[type="checkbox"][data-annexure-line]');
      if (box && t === annexureTbc(scope) && t.checked && box.checked) {
        box.checked = false;
        annexureApply(box);
      }
    });
  }

  // Owner decision D-339 (12 Nov 2026): "No. of pieces" on an order line. With Length and
  // Width (and Thickness for CBM) in CBM, SQM or SQFT the pieces are required and the Qty is
  // worked out from them (read-only): CBM per piece = L × W × H / 1,000,000 (4 decimals),
  // SQM = L × W / 10,000 (4 decimals), SQFT = SQM × 10.7639 (2 decimals); the Qty = per piece
  // × pieces (CBM 3 decimals, SQM / SQFT 2). PCS: the Qty is the pieces. Without a size the
  // Qty is typed as before. The server works the Qty out again and never trusts this figure.
  // The same rounding as LineTable.php / lineTable.js.
  var PIECE_UNITS = { CBM: 'CBM', M2: 'SQM', SQFT: 'SQFT', PCS: 'PCS' };
  var PIECE_ALL_DECIMALS = { CBM: 3, SQM: 2, SQFT: 2, PCS: 0 };
  var PIECE_ONE_DECIMALS = { CBM: 4, SQM: 2, SQFT: 2 };
  function roundHalfUp(v, d) { return Number(Math.round(Number(Number(v).toFixed(6) + 'e' + d)) + 'e-' + d); }
  function groupNum(v, d) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); }
  function piecesField(scope, base) {
    return scope.querySelector('[name="' + base + '"]') || scope.querySelector('[name="product_' + base + '[]"]');
  }
  function piecesNumber(scope, base) {
    var f = piecesField(scope, base);
    var v = f && !f.disabled ? f.value.trim() : '';
    return v !== '' && !isNaN(Number(v)) && Number(v) > 0 ? Number(v) : null;
  }
  function piecesPerPiece(code, l, w, h) {
    if (!code || code === 'PCS' || l === null || w === null) return null;
    if (code === 'CBM') return h === null ? null : roundHalfUp(l * w * h / 1000000, 4);
    var sqm = roundHalfUp(l * w / 10000, 4);
    return code === 'SQM' ? sqm : roundHalfUp(sqm * 10.7639, 2);
  }
  function piecesScope(el) { return el.closest('.product-row') || el.form; }
  function piecesNeeded(scope) {
    var annex = scope.querySelector('input[type="checkbox"][data-annexure-line]');
    if (annex && annex.checked) return null;
    var unit = piecesField(scope, 'unit');
    var code = PIECE_UNITS[unitKind(unit ? unit.value : '')] || null;
    var per = piecesPerPiece(code, piecesNumber(scope, 'piece_length_cm'), piecesNumber(scope, 'piece_width_cm'), piecesNumber(scope, 'piece_thickness_cm'));
    return per !== null ? { code: code, per: per } : null;
  }
  function piecesApply(scope) {
    var pcsField = scope.querySelector('[data-pieces-count]');
    if (!pcsField) return;
    var qty = piecesField(scope, 'quantity');
    var tbc = scope.querySelector('input[type="checkbox"][name="quantity_is_tbc"]') || scope.querySelector('input[type="checkbox"][name^="product_quantity_tbc"]');
    var hint = scope.querySelector('.qty-worked-out');
    var summary = scope.querySelector('.line-pieces-summary');
    var annex = scope.querySelector('input[type="checkbox"][data-annexure-line]');
    var unit = piecesField(scope, 'unit');
    var code = PIECE_UNITS[unitKind(unit ? unit.value : '')] || null;
    var raw = pcsField.value.trim();
    var n = /^\d{1,7}$/.test(raw) && Number(raw) > 0 ? Number(raw) : null;
    var need = piecesNeeded(scope);
    var priceField = piecesField(scope, 'unit_price');
    var price = priceField && priceField.value.trim() !== '' && !isNaN(Number(priceField.value)) ? Number(priceField.value) : null;
    var curSelect = document.getElementById('order-currency-select');
    var curOption = curSelect && curSelect.options[curSelect.selectedIndex];
    var cur = (summary && summary.getAttribute('data-currency')) || (curOption ? (curOption.getAttribute('data-code') || '') : '');
    var text = '';
    pcsField.disabled = !!(annex && annex.checked);
    if (pcsField.disabled) pcsField.value = '';
    var auto = false;
    if (qty && need) {
      auto = true;
      qty.value = n !== null ? roundHalfUp(need.per * n, PIECE_ALL_DECIMALS[need.code]).toFixed(PIECE_ALL_DECIMALS[need.code]) : '';
      if (hint) hint.textContent = 'worked out: ' + (n !== null ? groupNum(n, 0) : '…') + ' × ' + groupNum(need.per, PIECE_ONE_DECIMALS[need.code]) + ' ' + need.code;
      if (n !== null) {
        text = 'This line: ' + groupNum(need.per, PIECE_ONE_DECIMALS[need.code]) + ' ' + need.code + ' per piece · ' + groupNum(n, 0) + ' pcs = '
          + groupNum(Number(qty.value), PIECE_ALL_DECIMALS[need.code]) + ' ' + need.code;
        if (price !== null) {
          text += ' · ' + cur + ' ' + groupNum(roundHalfUp(price * need.per, 2), 2) + ' per piece · total ' + cur + ' ' + groupNum(roundHalfUp(Number(qty.value) * price, 2), 2);
        }
      }
    } else if (qty && code === 'PCS' && n !== null && !(annex && annex.checked)) {
      auto = true;
      qty.value = String(n);
      if (hint) hint.textContent = 'worked out: the No. of pieces';
      text = 'This line: ' + groupNum(n, 0) + ' pcs' + (price !== null ? ' · ' + cur + ' ' + groupNum(price, 2) + ' per piece · total ' + cur + ' ' + groupNum(roundHalfUp(n * price, 2), 2) : '');
    } else if (hint) {
      hint.textContent = '';
    }
    if (qty) {
      if (auto) {
        qty.readOnly = true;
        qty.classList.add('qty-auto');
        if (tbc) tbc.checked = false;
        qty.dispatchEvent(new Event('input', { bubbles: true }));
      } else if (qty.classList.contains('qty-auto')) {
        qty.readOnly = false;
        qty.classList.remove('qty-auto');
      }
    }
    if (summary) { summary.textContent = text; summary.hidden = text === ''; }
  }
  function initLinePieces() {
    document.querySelectorAll('[data-pieces-count]').forEach(function (f) { var sc = piecesScope(f); if (sc) piecesApply(sc); });
    var names = /^(product_)?(pieces_count|piece_length_cm|piece_width_cm|piece_thickness_cm|unit|unit_price|as_per_annexure)(\[\])?$/;
    var handler = function (e) {
      var t = e.target;
      if (!t || !t.name || !names.test(t.name)) return;
      var sc = piecesScope(t);
      if (sc && sc.querySelector('[data-pieces-count]')) piecesApply(sc);
    };
    document.addEventListener('input', handler);
    document.addEventListener('change', handler);
  }

  // D-300 (owner point 12, 11 Nov 2026): a supplier price for every thickness. Typing a
  // box makes it TYPED; every CALCULATED box follows at once from the category's rules
  // (30 mm = 20 mm + 35 %, 50 mm+ per CBM = 20 mm per m² × K — in every direction);
  // "↺ use calculated" goes back. The same maths as ThicknessPrices / thicknessPrices.js.
  function initThicknessGrid() {
    document.querySelectorAll('[data-thickness-grid]').forEach(function (table) {
      var k = parseFloat(table.dataset.k) || 0;
      var baseMm = parseInt(table.dataset.baseMm, 10);
      var rows = Array.prototype.slice.call(table.querySelectorAll('tr[data-mm]'));
      function parts(row) {
        return {
          mm: parseInt(row.dataset.mm, 10), cbm: row.dataset.cbm === '1', pct: parseFloat(row.dataset.pct || '0'),
          input: row.querySelector('input.grid-price'), how: row.querySelector('input[type="hidden"]'),
          badge: row.querySelector('[data-grid-badge]'), hint: row.querySelector('[data-grid-hint]'),
          use: row.querySelector('[data-grid-use-calc]'), perCbm: row.querySelector('[data-grid-percbm]')
        };
      }
      function round2(v) { return Math.round((v + 1e-9) * 100) / 100; }
      function money(v) { return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
      function typedValue(p) { var v = parseFloat(p.input.value); return p.how.value === 'typed' && v > 0 ? v : null; }
      function base() {
        var list = rows.map(parts);
        var i;
        for (i = 0; i < list.length; i++) if (!list[i].cbm && list[i].mm === baseMm && typedValue(list[i]) !== null) return typedValue(list[i]);
        for (i = 0; i < list.length; i++) if (!list[i].cbm && typedValue(list[i]) !== null) return typedValue(list[i]) / (1 + list[i].pct / 100);
        for (i = 0; i < list.length; i++) if (list[i].cbm && k > 0 && typedValue(list[i]) !== null) return typedValue(list[i]) / k;
        return null;
      }
      function recalc() {
        var b = base();
        rows.forEach(function (row) {
          var p = parts(row);
          if (!p.input || !p.how) return;
          var calc = b === null ? null : round2(p.cbm ? b * k : b * (1 + p.pct / 100));
          var typed = typedValue(p) !== null;
          if (!typed) {
            p.how.value = 'calculated';
            p.input.value = calc === null ? '' : calc.toFixed(2);
          }
          if (calc !== null) p.input.placeholder = calc.toFixed(2);
          row.classList.toggle('grid-typed', typed);
          row.classList.toggle('grid-calculated', !typed);
          if (p.badge) {
            p.badge.textContent = typed ? 'TYPED' : 'CALCULATED';
            p.badge.className = 'badge ' + (typed ? 'badge-typed' : 'badge-calculated');
          }
          var v = parseFloat(p.input.value);
          if (p.hint) p.hint.textContent = typed && calc !== null && Math.abs(calc - v) > 0.005 ? 'calculated would be ' + money(calc) : '';
          if (p.use) p.use.hidden = !typed;
          if (p.perCbm) p.perCbm.textContent = v > 0 ? '₹' + money(p.cbm ? v : v / (p.mm / 1000)) : '';
        });
      }
      rows.forEach(function (row) {
        var p = parts(row);
        if (!p.input || !p.how) return;
        p.input.addEventListener('input', function () {
          p.how.value = parseFloat(p.input.value) > 0 ? 'typed' : 'calculated';
          recalc();
        });
        if (p.use) {
          p.use.addEventListener('click', function () {
            p.how.value = 'calculated';
            recalc();
          });
        }
      });
      recalc();
    });
  }

  // D-335 (owner, 12 Nov 2026): "Add / update a price" — the amounts of "Also work out from
  // this … price" while typing: from the entered thickness the base thickness is worked back
  // (50 mm+: CBM ÷ K; another thickness: ÷ (1 + its %)), then each other thickness = base ×
  // (1 + its %), 50 mm+ = base × K. The server works them out again on save
  // (ThicknessPrices::calcFrom / thicknessPrices.calcFrom — same maths, same rounding).
  function initThicknessCalc() {
    document.querySelectorAll('[data-thickness-calc]').forEach(function (box) {
      var k = parseFloat(box.dataset.k) || 0;
      var baseMm = parseInt(box.dataset.baseMm, 10);
      var select = box.querySelector('[data-calc-thickness]');
      var priceInput = box.querySelector('[data-calc-price]');
      if (!select || !priceInput) return;
      function round2(v) { return Math.round((v + 1e-9) * 100) / 100; }
      function fmt(v) { return Number(v).toFixed(3).replace(/0+$/, '').replace(/\.$/, ''); }
      function money(v) { return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
      function amount(v) {
        v = round2(v);
        return Math.abs(v - Math.round(v)) < 0.005 ? Math.round(v).toLocaleString('en-US') : money(v);
      }
      function update() {
        var opt = select.options[select.selectedIndex];
        var from = select.value === '' ? null : parseInt(select.value, 10);
        var fromCbm = !!(opt && opt.dataset.cbm === '1');
        var fromPct = opt && opt.dataset.pct !== undefined ? parseFloat(opt.dataset.pct) : 0;
        var price = parseFloat(String(priceInput.value).replace(/,/g, ''));
        box.querySelectorAll('[data-calc-from]').forEach(function (el) { el.textContent = opt && from !== null ? opt.textContent.trim() : '…'; });
        var base = null;
        var back = '';
        if (from !== null && price > 0) {
          if (fromCbm) {
            if (k > 0) { base = price / k; back = amount(price) + ' per CBM ÷ ' + fmt(k); }
          } else if (1 + fromPct / 100 > 0) {
            base = price / (1 + fromPct / 100);
            back = from === baseMm ? '' : amount(price) + ' ÷ ' + fmt(1 + fromPct / 100);
          }
        }
        if (base !== null) base = round2(base);
        var root = base === null ? '' : (from === baseMm ? amount(price) : baseMm + ' mm price ₹' + amount(base) + ' (= ' + back + ')');
        box.querySelectorAll('[data-also-mm]').forEach(function (row) {
          var mm = parseInt(row.dataset.alsoMm, 10);
          var cbm = row.dataset.cbm === '1';
          var pct = parseFloat(row.dataset.pct || '0');
          row.hidden = from !== null && mm === from;
          var priceEl = row.querySelector('[data-also-price]');
          var howEl = row.querySelector('[data-also-how]');
          if (base === null) {
            if (priceEl) priceEl.textContent = '…';
            if (howEl) howEl.textContent = 'type the price to see it';
            return;
          }
          var v;
          var how;
          if (cbm) {
            v = round2(base * k);
            how = '= ' + baseMm + ' mm price ₹' + amount(base) + ' per m² × ' + fmt(k) + (from !== baseMm ? ' (' + baseMm + ' mm = ' + back + ')' : '');
          } else {
            v = round2(base * (1 + pct / 100));
            how = '= ' + root + (mm === baseMm ? '' : ' ' + (pct < 0 ? '− ' : '+ ') + fmt(Math.abs(pct)) + ' %');
          }
          if (priceEl) priceEl.textContent = money(v);
          if (howEl) howEl.textContent = how;
        });
      }
      select.addEventListener('change', update);
      priceInput.addEventListener('input', update);
      update();
    });
  }

  // D-303 (owner point 15): the supplier of a product price is picked from the Suppliers
  // directory — the hidden id follows the name typed or picked, and the location list is
  // the supplier's factory / quarry addresses (the first is filled in while empty).
  function initSupplierPick() {
    document.querySelectorAll('input[data-pick-id]').forEach(function (input) {
      var form = input.form;
      if (!form) return;
      var list = document.getElementById(input.getAttribute('list'));
      var idField = form.elements.namedItem(input.dataset.pickId);
      var locField = input.dataset.pickLocations ? form.elements.namedItem(input.dataset.pickLocations) : null;
      var locList = locField && locField.getAttribute ? document.getElementById(locField.getAttribute('list')) : null;
      function sync() {
        var match = null;
        var v = input.value.trim().toLowerCase();
        if (list) {
          for (var i = 0; i < list.options.length; i++) {
            if (list.options[i].value.trim().toLowerCase() === v) { match = list.options[i]; break; }
          }
        }
        if (idField && 'value' in idField) idField.value = match ? match.dataset.id : '';
        if (locList) {
          locList.innerHTML = '';
          var locs = match && match.dataset.locations ? match.dataset.locations.split('|') : [];
          locs.forEach(function (l) {
            var o = document.createElement('option');
            o.value = l;
            locList.appendChild(o);
          });
          if (locField && locField.value.trim() === '' && locs.length > 0) locField.value = locs[0];
        }
      }
      input.addEventListener('input', sync);
      input.addEventListener('change', sync);
      sync();
    });
  }

  // D-300 (owner point 12): the category's Price rules page — the example column follows
  // the thickness %s and × K as they are typed.
  function initRulesExample() {
    document.querySelectorAll('[data-rules-example]').forEach(function (form) {
      var ex = parseFloat(form.dataset.example) || 0;
      function fmt(v) { return '₹' + Math.round(v).toLocaleString('en-US'); }
      function update() {
        form.querySelectorAll('[data-rule-row]').forEach(function (row) {
          var input = row.querySelector('[data-rule-pct]');
          var cell = row.querySelector('[data-rule-example]');
          var pct = input ? parseFloat(input.value) : 0;
          if (isNaN(pct)) pct = 0;
          if (cell) cell.textContent = fmt(ex * (1 + pct / 100)) + ' / m²';
        });
        var kRow = form.querySelector('[data-rule-cbm]');
        if (kRow) {
          var kInput = kRow.querySelector('[data-rule-k]');
          var kv = kInput ? parseFloat(kInput.value) : NaN;
          var kCell = kRow.querySelector('[data-rule-example]');
          if (kCell) kCell.textContent = isNaN(kv) ? '—' : fmt(ex * kv) + ' / CBM';
        }
      }
      form.addEventListener('input', update);
      update();
    });
  }

  function fieldError(el) {
    var value = el.value.trim();
    var name = (el.name || '').replace(/\[.*$/, '');
    var type = (el.type || '').toLowerCase();
    // data-refuse-when="<name>=<value>": the form is not sent while that field
    // of the same form (enabled) has that value — e.g. D-244's "I typed
    // something wrong" keeps a remittance from saving until the figures agree.
    if (el.dataset.refuseWhen && el.form) {
      var refuse = el.dataset.refuseWhen.split('=');
      var refuseField = el.form.elements.namedItem(refuse[0]);
      if (refuseField && !refuseField.disabled && refuseField.value === refuse[1]) {
        return el.dataset.refuseMessage || 'Correct this field before saving.';
      }
    }
    // data-min-ticked="<name>,<name>,…:<n>": at least n of those checkboxes of
    // the same form are ticked (D-293: "How did you check the bank details?"
    // — tick at least two). Put it on the first checkbox of the group.
    if (el.dataset.minTicked && el.form) {
      var spec = el.dataset.minTicked.split(':');
      var need = parseInt(spec[1] || '1', 10);
      var ticked = spec[0].split(',').filter(function (n) { var c = el.form.elements.namedItem(n); return c && c.checked; }).length;
      if (ticked < need) return el.dataset.minTickedMessage || 'Tick at least ' + need + '.';
    }
    if (el.required && value === '' && (el.tagName === 'TEXTAREA' || type === 'text' || type === 'search' || type === 'email' || type === 'tel' || type === 'url' || type === 'password' || type === 'number')) {
      return 'This field is required.';
    }
    var mismatch = matchError(el);
    if (mismatch) return mismatch;
    // data-allowed-from="<select name>": the value must be one of the units
    // the chosen option of that select allows (its data-allowed-units, comma
    // separated; empty = any) — e.g. D-257 0B: a product category's units.
    if (el.dataset.allowedFrom && el.form && value !== '') {
      var from = el.form.elements.namedItem(el.dataset.allowedFrom);
      var opt = from && from.options ? from.options[from.selectedIndex] : null;
      var allowed = opt && !from.disabled && opt.dataset.allowedUnits ? opt.dataset.allowedUnits.split(',') : [];
      // D-270: Natural Stone's unit list applies to goods lines only (charges keep any unit).
      var lineTypeField = opt && opt.dataset.allowedGoodsOnly ? el.form.elements.namedItem('line_type') : null;
      if (lineTypeField && 'value' in lineTypeField && lineTypeField.value !== 'goods') allowed = [];
      if (allowed.length > 0 && allowed.indexOf(value.toUpperCase()) === -1) {
        return 'Choose one of the units for ' + opt.textContent.trim() + ': ' + allowed.join(', ') + '.';
      }
    }
    // data-in-list="<datalist id>" (D-302 / D-303, owner points 11 and 15): the value
    // must be one of that list's options — an HS code from the HS Code Master List, a
    // supplier from the Suppliers directory. Empty passes (required decides that).
    if (el.dataset.inList && value !== '') {
      var inList = document.getElementById(el.dataset.inList);
      if (inList && inList.options) {
        var listed = false;
        for (var li = 0; li < inList.options.length; li++) {
          if (inList.options[li].value.trim().toLowerCase() === value.toLowerCase()) { listed = true; break; }
        }
        if (!listed) return el.dataset.inListMessage || 'Choose one of the listed values.';
      }
    }
    // data-at-least-one="<name prefix>" (D-300, owner point 12): at least one enabled
    // field of the form whose name starts with that prefix holds a number above 0 (e.g.
    // the thickness prices — "Enter at least one price"); the message goes on the first.
    if (el.dataset.atLeastOne && el.form) {
      var group = el.form.querySelectorAll('[name^="' + el.dataset.atLeastOne + '"]:not([type="hidden"])');
      var anyFilled = false;
      for (var gi = 0; gi < group.length; gi++) {
        if (!group[gi].disabled && parseFloat(group[gi].value) > 0) { anyFilled = true; break; }
      }
      if (!anyFilled && group[0] === el) return el.dataset.atLeastOneMessage || 'Enter at least one value.';
    }
    // data-piece-size="length|width|thickness" (D-270, owner point 25): an order
    // line's piece size, needed when its unit must be converted to the product's
    // reference unit for the price check — PCS needs all three, RMT width and
    // thickness, m² / sq ft the thickness (unless the Thickness option gives it).
    if (el.dataset.pieceSize && value === '') {
      var sizeNeed = pieceSizeNeed(el);
      if (sizeNeed) return sizeNeed;
    }
    // data-pieces-count (D-339): an order line's No. of pieces — a whole number; required
    // when the size works out the Qty (CBM / SQM / SQFT with Length and Width, and Thickness for CBM).
    if (el.dataset.piecesCount !== undefined && !el.disabled) {
      if (value !== '' && (!/^\d{1,7}$/.test(value) || Number(value) < 1)) return 'Enter the No. of pieces as a whole number from 1 to 9999999 (e.g. 25).';
      var pcsScope = piecesScope(el);
      if (value === '' && pcsScope && piecesNeeded(pcsScope)) return 'Enter the No. of pieces — with the size given, the Qty is worked out from the pieces.';
    }
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
    // data-required-when="<field>=<value>": required only while another
    // field of the same form has that value (D-215: an HS code for a goods
    // line, a SAC code for a service line).
    if (el.dataset.requiredWhen && el.form && value === '') {
      var cond = el.dataset.requiredWhen.split('=');
      var other = el.form.elements.namedItem(cond[0]);
      // A checkbox counts only while ticked (D-255: the associate's "something not listed" box).
      var otherValue = other && (other.type === 'checkbox' || other.type === 'radio') ? (other.checked ? other.value : '') : (other ? other.value : '');
      if (other && otherValue === cond[1]) return el.dataset.requiredWhenMessage || 'This field is required.';
    }
    // data-not-with="<field>": this field and that field of the same form
    // cannot both be filled (QA batch 2, UI-8: a GST rate is for an HSN
    // prefix or for a category, never both).
    if (el.dataset.notWith && el.form && value !== '') {
      var partner = el.form.elements.namedItem(el.dataset.notWith);
      if (partner && String(partner.value || '').trim() !== '') return el.dataset.notWithMessage || 'Fill in only one of these fields.';
    }
    // data-not-before="<selector>": a date that cannot be earlier than another
    // date field in the same form (e.g. a GST refund claim's period end).
    if (el.dataset.notBefore && el.form && value !== '') {
      var start = el.form.querySelector(el.dataset.notBefore);
      if (start && start.value !== '' && value < start.value) {
        return el.dataset.notBeforeMessage || 'This date cannot be before ' + start.value + '.';
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
    // data-unique-among="<name prefix>": no other field of the form whose
    // name starts with that prefix may hold the same number (e.g. the
    // reward table's "profit from" rows — QA Round 6, R6-N12).
    if (el.dataset.uniqueAmong && el.form && value !== '') {
      var peers = el.form.querySelectorAll('[name^="' + el.dataset.uniqueAmong + '"]');
      for (var ui = 0; ui < peers.length; ui++) {
        if (peers[ui] !== el && peers[ui].value.trim() !== '' && parseFloat(peers[ui].value) === parseFloat(value)) {
          return el.dataset.uniqueAmongMessage || 'This value is already used in another row.';
        }
      }
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
    // QA UI-1: only typed reasons have a minimum length — a reason picked
    // from a list (<select name="difference_reason_choice">) carries an id.
    // QA UI-9: a field's own minlength (e.g. 5 on an agreement's end reason,
    // as the server allows) takes the place of the default.
    var reasonMin = el.minLength > 0 ? el.minLength : REASON_MIN_LENGTH;
    if ((el.tagName === 'TEXTAREA' || type === 'text') && /reason/i.test(name) && value !== '' && value.length < reasonMin) {
      return 'Reason must be at least ' + reasonMin + ' characters (' + value.length + ' so far).';
    }
    if (type === 'text' && NUMERIC_FIELD_NAME.test(name) && value !== '' && !/^\d*\.?\d+$/.test(value)) {
      return 'Enter a number — digits and a decimal point only (no commas, spaces or units).';
    }
    // Every other built-in rule (required selects, dates and files, min /
    // max, minlength, pattern, email...) — so every form, present and
    // future, is checked before it is sent, with the message beside the field.
    if (el.willValidate && el.validity && !el.validity.valid) {
      // D-251: a pattern set from a chosen option carries its own plain message.
      if (el.validity.patternMismatch && el.getAttribute('data-pattern-message')) return el.getAttribute('data-pattern-message');
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

  // Owner decision 4 Nov 2026 (B, mockup approved): when a form cannot be
  // saved, a pop-up lists every problem — each line a link that opens the
  // field's step card and puts the cursor in it. The messages beside the
  // fields stay. The same pop-up repeats a server error message after the
  // page reloads. It does not block the page (role="alertdialog", not
  // modal): the cursor goes to the first problem so the user can type at
  // once; Tab stays inside the pop-up while it has the focus; Esc closes it.
  // Lines disappear as their fields are fixed; it closes when none are left.
  var errorPopup = null;

  function fieldLabelText(el) {
    var text = '';
    if (el.labels && el.labels.length) {
      var label = el.labels[0].cloneNode(true);
      Array.prototype.forEach.call(label.querySelectorAll('input, select, textarea, small, .muted, .form-field-error'), function (n) { n.remove(); });
      text = label.textContent;
    }
    text = (text || el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.name || 'This field').replace(/\*/g, '').replace(/\s+/g, ' ').trim();
    return text.length > 80 ? text.slice(0, 77) + '…' : text;
  }

  function goToField(el) {
    revealElement(el);
    if (typeof el.scrollIntoView === 'function') el.scrollIntoView({ block: 'center' });
    if (typeof el.focus === 'function') el.focus();
  }

  function closeErrorPopup() {
    if (errorPopup) {
      errorPopup.remove();
      errorPopup = null;
    }
  }

  function updateErrorPopupTitle() {
    if (!errorPopup) return;
    var left = errorPopup.querySelectorAll('li').length;
    if (left === 0) { closeErrorPopup(); return; }
    var title = errorPopup.querySelector('.error-popup-title');
    title.textContent = '⚠ ' + errorPopup.getAttribute('data-heading') + (errorPopup.getAttribute('data-count') === '1'
      ? ' — ' + left + (left === 1 ? ' thing' : ' things') + ' to fix' : '');
  }

  // items: [{ el: <field or element to go to>, label: 'Field name', message: '...' }]
  function showErrorPopup(items, heading) {
    closeErrorPopup();
    if (!items.length) return;
    var box = document.createElement('div');
    box.className = 'error-popup';
    box.setAttribute('role', 'alertdialog');
    box.setAttribute('aria-modal', 'false');
    box.setAttribute('aria-labelledby', 'error-popup-title');
    box.setAttribute('data-heading', heading || 'Not saved');
    box.setAttribute('data-count', heading ? '0' : '1');
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'error-popup-close';
    close.setAttribute('aria-label', 'Close');
    close.textContent = '×';
    close.addEventListener('click', closeErrorPopup);
    var title = document.createElement('h4');
    title.id = 'error-popup-title';
    title.className = 'error-popup-title';
    var list = document.createElement('ol');
    items.forEach(function (item) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = '#';
      a.textContent = item.label;
      a.addEventListener('click', function (e) { e.preventDefault(); goToField(item.el); });
      li.appendChild(a);
      if (item.message) li.appendChild(document.createTextNode(' — ' + item.message));
      li._errorField = item.el;
      list.appendChild(li);
    });
    var first = document.createElement('button');
    first.type = 'button';
    first.className = 'btn-sm error-popup-first';
    first.textContent = items.length > 1 ? 'Take me to the first one' : 'Take me there';
    first.addEventListener('click', function () {
      var li = list.querySelector('li');
      if (li) goToField(li._errorField);
    });
    var hint = document.createElement('span');
    hint.className = 'muted small';
    hint.textContent = items.length > 1 ? ' Click a line to jump to that field.' : '';
    box.appendChild(close);
    box.appendChild(title);
    box.appendChild(list);
    box.appendChild(first);
    box.appendChild(hint);
    box.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var focusables = box.querySelectorAll('a, button');
      if (!focusables.length) return;
      var firstEl = focusables[0];
      var lastEl = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === firstEl) { e.preventDefault(); lastEl.focus(); }
      else if (!e.shiftKey && document.activeElement === lastEl) { e.preventDefault(); firstEl.focus(); }
    });
    document.body.appendChild(box);
    errorPopup = box;
    updateErrorPopupTitle();
  }

  function initErrorPopup() {
    document.addEventListener('keydown', function (e) {
      if (errorPopup && (e.key === 'Escape' || e.key === 'Esc')) closeErrorPopup();
    });
    // A line goes as soon as its field is fixed.
    var recheck = function (e) {
      if (!errorPopup) return;
      Array.prototype.forEach.call(errorPopup.querySelectorAll('li'), function (li) {
        var el = li._errorField;
        if (el === e.target && el.classList && !el.classList.contains('field-invalid')) li.remove();
      });
      updateErrorPopupTitle();
    };
    document.addEventListener('input', recheck);
    document.addEventListener('change', recheck);
    // The browser's own check can stop a submit before the submit listener
    // runs: gather the fields it flags in this one go and list them too.
    var pending = [];
    document.addEventListener('invalid', function (e) {
      var el = e.target;
      if (!el || !('value' in el)) return;
      if (!pending.length) {
        setTimeout(function () {
          var items = pending;
          pending = [];
          showErrorPopup(items);
          if (items[0] && document.activeElement !== items[0].el) goToField(items[0].el);
        }, 0);
      }
      pending.push({ el: el, label: fieldLabelText(el), message: fieldError(el) || el.validationMessage || 'Please check this field.' });
    }, true);
    // A server error after the page reloads (a form page): the same pop-up repeats it.
    var hasForm = Array.prototype.some.call(document.forms, function (f) {
      return (f.getAttribute('method') || '').toLowerCase() === 'post' && !/\/logout$/.test(f.getAttribute('action') || '');
    });
    var alerts = document.querySelectorAll('.alert-error');
    if (!hasForm || !alerts.length) return;
    var items = [];
    Array.prototype.forEach.call(alerts, function (alertEl) {
      if (!alertEl.hasAttribute('tabindex')) alertEl.setAttribute('tabindex', '-1');
      items.push({ el: alertEl, label: alertEl.textContent.replace(/\s+/g, ' ').trim(), message: '' });
    });
    showErrorPopup(items, 'Not saved — please check');
  }

  function initSubmitValidation() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement) || form.noValidate) return;
      if (e.submitter && e.submitter.formNoValidate) return;
      var firstInvalid = null;
      var problems = [];
      Array.prototype.forEach.call(form.elements, function (el) {
        if (el.disabled || !('value' in el) || !el.name || (el.type || '').toLowerCase() === 'hidden') return;
        var message = fieldError(el);
        if (message) {
          showFieldError(el, message);
          firstInvalid = firstInvalid || el;
          problems.push({ el: el, label: fieldLabelText(el), message: message });
        } else {
          clearFieldError(el);
        }
      });
      if (!firstInvalid) { closeErrorPopup(); return; }
      e.preventDefault();
      e.stopImmediatePropagation();
      showErrorPopup(problems);
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
      showFieldError(el, fieldError(el) || el.validationMessage || 'Please check this field.');
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
        // A tick box counts with its value only while ticked (D-335: "Use own rules for this product").
        var value = field && field.type === 'checkbox' ? (field.checked ? field.value : '') : (field ? field.value : null);
        var show = !!field && value === cond[1];
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

  // data-fills='{"<name>":"<value>",…}' on a select's option: choosing it
  // fills those fields of the same form (text fields only while empty) — e.g.
  // D-257 0B: a product category's default HS code and unit on a new line.
  function initOptionFills() {
    document.addEventListener('change', function (e) {
      var select = e.target;
      if (!select || select.tagName !== 'SELECT' || !select.form) return;
      var opt = select.options[select.selectedIndex];
      if (!opt || !opt.dataset.fills) return;
      var fills;
      try { fills = JSON.parse(opt.dataset.fills); } catch (err) { return; }
      Object.keys(fills).forEach(function (name) {
        var field = select.form.elements.namedItem(name);
        // A text field is filled only while empty; a drop-down (e.g. Unit) takes the option's value.
        if (field && 'value' in field && !field.disabled && fills[name] && (field.tagName === 'SELECT' || field.value.trim() === '')) {
          field.value = fills[name];
          field.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
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

  // QA Round 8, R8-N08: every required field's label shows "*", once.
  // A label already written with "*" in the view is left alone; a label
  // whose field is required but which has no "*" gets
  // <span class="req-star"> *</span> after its text. When the field stops
  // being required (show-when rows, "not applicable" rows, a field switched
  // off by a script) the added star goes again. Checkboxes and radios are
  // confirmations or choices, not fields to fill, so they get no star.
  function starredControls(label) {
    var forId = label.getAttribute('for');
    if (forId) {
      var target = document.getElementById(forId);
      return target ? [target] : [];
    }
    return Array.prototype.slice.call(label.querySelectorAll('input, select, textarea'));
  }

  function needsStar(el) {
    var type = (el.getAttribute('type') || '').toLowerCase();
    if (type === 'hidden' || type === 'checkbox' || type === 'radio' || type === 'submit' || type === 'button' || type === 'reset' || type === 'image') return false;
    return el.required && !el.disabled;
  }

  function writtenLabelText(label) {
    var text = '';
    label.childNodes.forEach(function (node) {
      if (node.nodeType === 3) { text += node.textContent; return; }
      if (node.nodeType !== 1 || node.classList.contains('req-star')) return;
      if (/^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(node.tagName) || node.querySelector('input, select, textarea')) return;
      text += node.textContent;
    });
    return text;
  }

  function starPlace(label) {
    for (var i = 0; i < label.childNodes.length; i++) {
      var node = label.childNodes[i];
      if (node.nodeType !== 1) continue;
      if (/^(INPUT|SELECT|TEXTAREA|BUTTON|BR)$/.test(node.tagName) || node.querySelector('input, select, textarea')) return node;
    }
    return null;
  }

  function markRequiredLabels() {
    document.querySelectorAll('label').forEach(function (label) {
      var required = starredControls(label).some(needsStar);
      var added = label.querySelector(':scope > .req-star');
      var written = writtenLabelText(label).indexOf('*') !== -1;
      if (required && !written && !added) {
        var star = document.createElement('span');
        star.className = 'req-star';
        star.textContent = ' *';
        var place = starPlace(label);
        // Trailing spaces before the field would put a gap before the star.
        var prev = place ? place.previousSibling : label.lastChild;
        if (prev && prev.nodeType === 3) prev.textContent = prev.textContent.replace(/\s+$/, '');
        label.insertBefore(star, place);
      } else if (added && (!required || written)) {
        added.remove();
      }
    });
  }

  function initRequiredLabels() {
    markRequiredLabels();
    if (!window.MutationObserver) return;
    var queued = false;
    new MutationObserver(function () {
      if (queued) return;
      queued = true;
      setTimeout(function () { queued = false; markRequiredLabels(); }, 0);
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['required', 'disabled'] });
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

  // Owner decision D-340 (12 Nov 2026): on Pricing settings the badges beside the
  // exchange-rate fields ("in use now", "not used now — fall-back only", "not used in
  // Manual") and the muted look of the unused field follow the mode drop-down as it is
  // changed. The page renders them right on load; this only keeps them in step.
  function applyFxModeBadges(select) {
    var box = select.closest('[data-fx-has-market]');
    if (!box) return;
    var key = select.value === 'manual' ? 'manual' : (box.getAttribute('data-fx-has-market') === '1' ? 'auto-market' : 'auto-none');
    box.querySelectorAll('[data-fx-when]').forEach(function (b) {
      b.hidden = b.getAttribute('data-fx-when').split(' ').indexOf(key) === -1;
    });
    box.querySelectorAll('[data-fx-muted-when]').forEach(function (f) {
      f.classList.toggle('fx-unused', f.getAttribute('data-fx-muted-when').split(' ').indexOf(key) !== -1);
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target && e.target.matches && e.target.matches('select[data-fx-mode]')) applyFxModeBadges(e.target);
  });

  // Owner decision D-320 (point 26): the section menu bar's drop-downs (and
  // the order page's "More" menu) close when another opens or on a click
  // elsewhere; a menu link to a part of a page (#exchange-rate,
  // #settings-security, #document-review-approval …) opens the closed card
  // that holds it and scrolls to it.
  function initSectionMenus() {
    var menus = document.querySelectorAll('.section-nav-drop > details, .order-action-more');
    menus.forEach(function (d) {
      d.addEventListener('toggle', function () {
        if (!d.open) return;
        menus.forEach(function (other) { if (other !== d) other.open = false; });
      });
    });
    document.addEventListener('click', function (e) {
      menus.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
    });
    function openTarget() {
      var id = (window.location.hash || '').replace('#', '');
      if (!id) return;
      var el = document.getElementById(id);
      if (!el) return;
      if (el.tagName === 'DETAILS') el.open = true;
      revealElement(el);
      el.scrollIntoView({ block: 'start' });
    }
    openTarget();
    window.addEventListener('hashchange', openTarget);
  }

  // Owner request (3 Nov 2026): after a link is generated the page comes back to
  // that card (…?open=<card>#<card>); the order page's own script opens and scrolls
  // to it. Then the new link is focused, selected (ready to copy) and briefly
  // highlighted, so staff copy it and send it straight away.
  function initFocusLink() {
    var id = (window.location.hash || '').replace('#', '');
    if (!id) return;
    var card = document.getElementById(id);
    if (!card) return;
    var input = card.querySelector('[data-focus-link]');
    if (!input) return;
    window.setTimeout(function () {
      revealElement(input);
      input.scrollIntoView({ block: 'center' });
      try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); }
      if (typeof input.select === 'function') input.select();
      var before = input.style.boxShadow;
      input.style.boxShadow = '0 0 0 3px rgba(46, 125, 86, 0.55)';
      window.setTimeout(function () { input.style.boxShadow = before; }, 2500);
    }, 80);
  }

  // Owner request (3 Nov 2026): a click anywhere in a date box opens the calendar,
  // not only a click on the small calendar icon. Typing still works; browsers
  // without showPicker() simply keep their usual behaviour.
  // Owner decision D-245 P2: data-floor="<number>" on a price field — the
  // lowest price this person may agree for the line. Below it the message in
  // data-floor-message (or a default) shows beside the field as the user
  // types; the line can still be saved, and is then sent to the MD for
  // approval (the server checks it again and never accepts a price below the
  // product's minimum). The floor changes when "Pick from Products" fills the
  // line, so it is read afresh on every input.
  function floorNote(el) {
    var note = el.parentElement.querySelector('.floor-note');
    var floor = parseFloat(el.dataset.floor);
    var value = parseFloat(el.value);
    var below = el.dataset.floor !== undefined && el.dataset.floor !== '' && !isNaN(floor) && el.value.trim() !== '' && !isNaN(value) && value < floor - 0.0001;
    if (!below) {
      el.classList.remove('below-floor');
      // D-339: on an order line, the floor in the line's unit beside the price ("Your floor: USD 37.20 per SQM — this price is 4.8% above").
      if (el.hasAttribute('data-floor-show') && el.dataset.floor !== undefined && el.dataset.floor !== '' && !isNaN(floor) && floor > 0 && el.value.trim() !== '' && !isNaN(value)) {
        if (!note) {
          note = document.createElement('span');
          note.setAttribute('aria-live', 'polite');
          el.insertAdjacentElement('afterend', note);
        }
        note.className = 'floor-note floor-ok small';
        var sc = el.closest('.product-row') || el.form;
        var u = sc ? (sc.querySelector('[name="unit"]') || sc.querySelector('[name="product_unit[]"]')) : null;
        var pct = Math.round((value - floor) / floor * 1000) / 10;
        var curSel = document.getElementById('order-currency-select');
        var curOpt = curSel && curSel.options[curSel.selectedIndex];
        var floorCur = el.getAttribute('data-floor-currency') || (curOpt ? (curOpt.getAttribute('data-code') || '') : '');
        note.textContent = 'Your floor: ' + (floorCur ? floorCur + ' ' : '') + groupNum(floor, 2) + (u && u.value ? ' per ' + u.value : '')
          + (pct > 0 ? ' — this price is ' + pct.toFixed(1) + '% above' : ' — this price is at your floor');
        return;
      }
      if (note) note.remove();
      return;
    }
    if (note) note.className = 'floor-note small';
    if (!note) {
      note = document.createElement('span');
      note.className = 'floor-note small';
      note.setAttribute('aria-live', 'polite');
      el.insertAdjacentElement('afterend', note);
    }
    note.textContent = (el.dataset.floorMessage || 'Below your lowest price') + ' (' + floor.toFixed(2) + ') — saving sends this line to the MD for approval; the Proforma Invoice waits for it.';
    el.classList.add('below-floor');
  }

  function initFloorNotes() {
    document.addEventListener('input', function (e) {
      if (e.target.matches && e.target.matches('[data-floor]')) floorNote(e.target);
    });
    document.addEventListener('floor-changed', function (e) {
      if (e.target.matches && e.target.matches('[data-floor]')) floorNote(e.target);
    });
    document.querySelectorAll('[data-floor]').forEach(floorNote);
  }

  // Owner decision D-251 (4 Nov 2026): a select whose options carry data-pattern / data-placeholder /
  // data-title sets those on the input named by data-pattern-target (e.g. the document number of an
  // associate document: a PAN, the last 4 Aadhaar digits, a GSTIN). The usual submit check then
  // shows the option's title beside the field when the value does not match.
  function applyPatternFrom(select) {
    var target = select.form ? select.form.querySelector(select.getAttribute('data-pattern-target')) : null;
    var opt = select.options[select.selectedIndex];
    if (!target || !opt) return;
    var pattern = opt.getAttribute('data-pattern');
    if (pattern) target.setAttribute('pattern', pattern); else target.removeAttribute('pattern');
    var title = opt.getAttribute('data-title');
    // title: the browser's own pop-up shows it under its generic "match the format" line.
    if (title) { target.setAttribute('data-pattern-message', title); target.setAttribute('title', title); } else { target.removeAttribute('data-pattern-message'); target.removeAttribute('title'); }
    var placeholder = opt.getAttribute('data-placeholder');
    if (placeholder) target.setAttribute('placeholder', placeholder);
  }

  function initPatternFromSelect() {
    document.addEventListener('change', function (e) {
      if (e.target.matches && e.target.matches('select[data-pattern-target]')) applyPatternFrom(e.target);
    });
    document.querySelectorAll('select[data-pattern-target]').forEach(applyPatternFrom);
  }

  function initDatePickers() {
    document.addEventListener('click', function (e) {
      var input = e.target;
      if (!(input instanceof HTMLInputElement)) return;
      if (['date', 'datetime-local', 'month', 'week', 'time'].indexOf(input.type) === -1) return;
      if (input.readOnly || input.disabled || typeof input.showPicker !== 'function') return;
      try { input.showPicker(); } catch (err) { /* not allowed here — ignore */ }
    });
  }

  // Owner decision D-280 (11 Nov 2026): a link shown with a Copy button
  // (<button data-copy="…">) — set-password links, PI form / payment links,
  // the permanent login pages. Copies the value and says "Copied".
  function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = value;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      document.body.appendChild(ta);
      ta.select();
      try {
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (ok) { resolve(); } else { reject(new Error('copy refused')); }
      } catch (err) {
        document.body.removeChild(ta);
        reject(err);
      }
    });
  }

  function initCopyButtons() {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-copy]') : null;
      if (!btn) return;
      e.preventDefault();
      var original = btn.getAttribute('data-copy-label') || btn.textContent;
      btn.setAttribute('data-copy-label', original);
      copyText(btn.getAttribute('data-copy') || '').then(function () {
        btn.textContent = 'Copied';
      }, function () {
        btn.textContent = 'Select and copy it';
      });
      setTimeout(function () { btn.textContent = original; }, 2000);
    });
  }

  // Owner decision D-283 (11 Nov 2026): a warning 2 minutes before the idle
  // logout, with "Stay logged in". Pages inside a signed-in area carry
  // <meta name="session-idle" content="<idle seconds>" data-warn data-keepalive
  // data-login data-csrf [data-absolute-left]>. Activity in another tab of the
  // same browser counts too (shared through localStorage when it is there).
  // The staff 12-hour limit cannot be extended: that warning only says so.
  function initIdleWarning() {
    var meta = document.querySelector('meta[name="session-idle"]');
    if (!meta) return;
    var idle = parseInt(meta.getAttribute('content'), 10) || 0;
    if (idle <= 0) return;
    var warn = parseInt(meta.getAttribute('data-warn'), 10) || 120;
    var absAttr = meta.getAttribute('data-absolute-left');
    var absoluteEnd = absAttr !== null && absAttr !== '' ? Date.now() + parseInt(absAttr, 10) * 1000 : null;
    var keepalive = meta.getAttribute('data-keepalive');
    var login = meta.getAttribute('data-login') || '/';
    var csrf = meta.getAttribute('data-csrf') || '';
    var storeKey = 'session-last-activity:' + keepalive;
    var last = Date.now();
    var box = null;
    var timer = null;

    function readShared() {
      try {
        var v = parseInt(window.localStorage.getItem(storeKey), 10);
        if (v > last) last = v;
      } catch (err) { /* no storage: this tab only */ }
    }
    function writeShared() {
      try { window.localStorage.setItem(storeKey, String(last)); } catch (err) { /* ignore */ }
    }
    function build() {
      if (box) return box;
      box = document.createElement('div');
      box.className = 'idle-warning';
      box.setAttribute('role', 'alertdialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', 'idle-warning-title');
      box.hidden = true;
      box.innerHTML = '<div class="idle-warning-box">'
        + '<h2 id="idle-warning-title">You will be signed out soon</h2>'
        + '<p class="idle-warning-text"></p>'
        + '<div class="btn-row"><button type="button" class="idle-warning-stay">Stay logged in</button></div>'
        + '</div>';
      document.body.appendChild(box);
      box.querySelector('.idle-warning-stay').addEventListener('click', stay);
      return box;
    }
    function show(text, canStay) {
      build();
      box.querySelector('.idle-warning-text').textContent = text;
      box.querySelector('.idle-warning-stay').hidden = !canStay;
      box.hidden = false;
      if (canStay) box.querySelector('.idle-warning-stay').focus();
    }
    function hide() {
      if (box) box.hidden = true;
    }
    function stay() {
      var body = '_csrf=' + encodeURIComponent(csrf);
      fetch(keepalive, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body
      }).then(function (r) {
        var type = r.headers.get('Content-Type') || '';
        if (!r.ok || type.indexOf('application/json') === -1) throw new Error('signed out');
        return r.json();
      }).then(function (data) {
        if (!data || !data.ok) throw new Error('signed out');
        last = Date.now();
        writeShared();
        hide();
      }).catch(function () {
        window.location.href = login;
      });
    }
    function tick() {
      readShared();
      var now = Date.now();
      var idleLeft = Math.round((last + idle * 1000 - now) / 1000);
      var absLeft = absoluteEnd !== null ? Math.round((absoluteEnd - now) / 1000) : null;
      if (idleLeft <= 0 || (absLeft !== null && absLeft <= 0)) {
        clearInterval(timer);
        window.location.href = login;
        return;
      }
      if (absLeft !== null && absLeft <= warn && absLeft <= idleLeft) {
        show('For security, staff log in again after a set number of hours. You will be signed out in about '
          + Math.max(1, Math.ceil(absLeft / 60)) + ' minute(s) — save your work now.', false);
      } else if (idleLeft <= warn) {
        show('You have not done anything for a while. You will be signed out in about '
          + Math.max(1, Math.ceil(idleLeft / 60)) + ' minute(s).', true);
      } else {
        hide();
      }
    }
    writeShared();
    timer = setInterval(tick, 5000);
  }

  function init() {
    // Before initConfirmDialogs(): both listen in the capture phase, so
    // registering this first means an invalid form is stopped before any
    // confirm() dialog is shown for it.
    initSubmitValidation();
    initErrorPopup();
    initConfirmDialogs();
    initKeepReportDivision();
    initSwitchViewKeepsFilters();

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
    initRemittanceInr();
    initRemittanceStatements();
    initRemittanceDifference();
    initSchemeRows();
    initGstCalc();
    initShowWhen();
    initOptionFills();
    initAutoReasons();
    initFieldLabels();
    initRequiredLabels();
    initStepStatusClasses();
    initDatePickers();
    initFocusLink();
    initFloorNotes();
    initPatternFromSelect();
    initCopyButtons(); // D-280
    initIdleWarning(); // D-283
    initThicknessGrid();
    initAnnexureLine(); // D-336
    initLinePieces(); // D-339
    initThicknessCalc(); // D-335
    initSupplierPick();
    initRulesExample();
    initSectionMenus(); // D-320
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
