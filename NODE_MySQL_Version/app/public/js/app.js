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

  function init() {
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
    });

    initSlowDownloadLinks();
    initTableScrollShadows();
    initReasonFieldValidation();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
