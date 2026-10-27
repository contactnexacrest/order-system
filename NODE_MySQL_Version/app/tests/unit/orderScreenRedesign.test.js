'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Point 2/4 — New Order form section redesign (merged Commercial & Shipping
 * Terms fieldset, 2-fields-per-row via .field-grid, weight-label tags,
 * freight-range explanation) and the order detail page's Concept C
 * sidebar + single-panel layout (progressive enhancement: every existing
 * section stays in the DOM and renders normally if JS never runs).
 */
describe('Order screen redesign (Point 2/4)', () => {
  const viewsDir = path.join(__dirname, '../../views');
  const cssPath = path.join(__dirname, '../../public/css/app.css');

  function createNjk() {
    return fs.readFileSync(path.join(viewsDir, 'orders/create.njk'), 'utf8');
  }

  function showNjk() {
    return fs.readFileSync(path.join(viewsDir, 'orders/show.njk'), 'utf8');
  }

  it('New Order form merges Commercial and Shipping into one two-column fieldset', () => {
    const html = createNjk();
    expect(html).toContain('Commercial &amp; Shipping Terms');
    expect(html).not.toContain('<legend>Commercial Terms</legend>');
    expect(html).not.toContain('<legend>Shipping</legend>');

    const incotermPos = html.indexOf('name="incoterm_id"');
    const portOfLoadingPos = html.indexOf('name="port_of_loading_id"');
    expect(incotermPos).toBeGreaterThan(-1);
    expect(portOfLoadingPos).toBeGreaterThan(incotermPos);
  });

  it('New Order form uses field-grid for two-per-row layout', () => {
    const html = createNjk();
    const count = (html.match(/class="field-grid"/g) || []).length;
    expect(count).toBeGreaterThanOrEqual(3);
  });

  it('weight fields are labelled with product-vs-packing hint', () => {
    const html = createNjk();
    expect(html).toContain('tag-hint">product + packing<');
    expect(html).toContain('tag-hint">product only<');
  });

  // 90-item backlog #67 superseded this field's original behavior: the
  // low/high range this test used to pin became a single indicative
  // figure, and the literal "order currency" placeholder text became a
  // live label reflecting the currency actually selected above — see
  // freightInsuranceSingleValue.test.js for the full coverage of that change.
  it('freight section explains the single indicative figure', () => {
    const html = createNjk();
    expect(html).toContain('current indicative rate only');
    expect(html).toContain('id="freight-currency-unit"');
  });

  /**
   * External QA report: the approved "NexaCrest: Standard vs Kanban UI"
   * design (KanbanDetail.dc.html/Main.dc.html) replaced the Concept C
   * sidebar + single-panel layout with a vertical sequence of
   * <details>/<summary> step-cards plus a persistent reference sidebar
   * (documents + at-a-glance) — reached identically whether the order
   * was opened from the Standard list or the Kanban board.
   */
  it('order show page wraps all sections in a step-card sequence plus sidebar', () => {
    const html = showNjk();
    expect(html).toContain('id="order-layout"');
    expect(html).toContain('id="order-panels"');
    expect(html).toContain('id="order-detail-sidebar"');
    expect(html).toContain('class="step-sequence"');

    const layoutStart = html.indexOf('id="order-layout"');
    const panelsStart = html.indexOf('id="order-panels"');
    const sidebarStart = html.indexOf('id="order-detail-sidebar"');
    expect(layoutStart).toBeGreaterThan(-1);
    expect(panelsStart).toBeGreaterThan(layoutStart);
    expect(sidebarStart).toBeGreaterThan(panelsStart);

    // Every existing top-level section must now live inside the
    // step-sequence wrapper as its own step-card — nothing was dropped,
    // only re-parented and re-labelled with the user's own 0-17 step
    // numbering.
    // Counted from the page body: shared card macros defined above it
    // (e.g. the two Compliance Checklist cards, rendered inside Steps 7 and
    // 10) aren't sections of their own.
    const bodyStart = html.indexOf('<div class="card page-wide">');
    const sectionCountTotal = (html.slice(bodyStart).match(/<details class="step-card/g) || []).length;
    const afterPanels = html.slice(panelsStart);
    const sectionCountInsidePanels = (afterPanels.match(/<details class="step-card/g) || []).length;
    expect(sectionCountInsidePanels).toBe(sectionCountTotal);
    expect(sectionCountTotal).toBeGreaterThanOrEqual(18);
  });

  it('order show page degrades gracefully without JavaScript', () => {
    const html = showNjk();
    // Step-cards are native <details>/<summary> — collapse/expand,
    // keyboard toggling, and reaching every card's content all come from
    // the browser itself, with zero script required (unlike the old
    // design, whose CSS hid every inactive panel behind a JS-built
    // ".js-enabled" gate). There is nothing left to gate.
    expect(html).not.toContain('order-sidebar');
    expect(html).not.toContain('active-panel');
    expect(html).not.toContain('js-enabled');
    expect((html.match(/<details class="step-card/g) || []).length).toBeGreaterThanOrEqual(18);
  });

  it('order show page preserves the #order-updates anchor id', () => {
    const html = showNjk();
    expect(html).toContain('id="order-updates"');
  });

  /**
   * QA Round 4, N-01: this template was previously missing a closing
   * </div>, silently nesting every panel after it one level deep. This
   * pins the structural invariant directly (not just "the count
   * matches", which the test above already checks but which a
   * missing-closing-tag bug can still satisfy by accident) — walks the
   * div/details nesting depth and fails if a lower-than-expected count
   * of step-cards are a direct child of #order-panels.
   *
   * Since the parent-group redesign, a direct child of #order-panels is
   * now either (a) a standalone step-card, same as before, or (b) a
   * <details class="step-group"> wrapper — now itself collapsible
   * (default-collapsed unless it's the order's current stage, same
   * stepCardOpen()/stepStatusIcon() discipline as every step-card)
   * rather than an always-visible <div> — for a multi-letter step's own
   * cards (4a/4b/4c, etc.), each of which sits one level deeper (depth
   * 3) as that group's own direct child. Both patterns are asserted
   * explicitly, so N-01's original bug class (a panel's own markup
   * never closing, silently nesting every later panel inside it) still
   * fails this test — on either axis, a card swallowed by an unclosed
   * sibling either vanishes from its expected depth or appears one
   * level deeper than it should.
   */
  it('every top-level section is a direct child of #order-panels', () => {
    const html = showNjk();
    const panelsOpenPos = html.indexOf('<div class="step-sequence" id="order-panels">');
    expect(panelsOpenPos).toBeGreaterThan(-1);

    const tokens = html.slice(panelsOpenPos).match(/<div\b[^>]*>|<\/div>|<details\b[^>]*>|<\/details>/g) || [];

    // Owner request (2026-10-26): the steps now sit inside five major
    // section boxes (<details class="phase-group">), so the expected shape
    // is: #order-panels > [reference cards | phase-group] > [step-card |
    // step-group] > step-card. A stack of each open element's kind tells
    // exactly where every card landed.
    const stack = [];
    const counts = { direct: 0, phases: 0, phaseCards: 0, groups: 0, groupedCards: 0 };
    for (const token of tokens) {
      if (token === '</div>' || token === '</details>') {
        stack.pop();
        if (stack.length === 0) break; // #order-panels itself has closed
        continue;
      }
      let kind = 'other';
      if (token.startsWith('<details class="phase-group"')) kind = 'phase';
      else if (token.startsWith('<details class="step-group"')) kind = 'group';
      else if (token.startsWith('<details class="step-card')) kind = 'card';
      const path = stack.join('>');
      if (stack.length) {
        if (kind === 'card' && path === 'panels') counts.direct++;
        else if (kind === 'phase' && path === 'panels') counts.phases++;
        else if (kind === 'card' && path === 'panels>phase') counts.phaseCards++;
        else if (kind === 'group' && path === 'panels>phase') counts.groups++;
        else if (kind === 'card' && path === 'panels>phase>group') counts.groupedCards++;
      }
      stack.push(stack.length ? kind : 'panels');
    }

    // Products, Payment Overview, Document Review, Order Communication (pinned) + the two admin cards.
    expect(counts.direct).toBe(6);
    expect(counts.phases).toBe(5);
    // Steps 1, 2, 3, the LC card, 19, Amendments, 14, 16-18, Logistics Partner Bills (Step 12 is a step-group, 12a-12d).
    expect(counts.phaseCards).toBe(11);
    expect(counts.groups).toBe(10);
    expect(counts.groupedCards).toBe(45);
  });

  it('Not Applicable / Not Eligible fill their reason in, and "+ Add a cost" folds', () => {
    // Owner request (2026-10-27): "Not Applicable" / "Not Eligible" fill
    // the reason in; a plain "Skipped" still needs one typed.
    const html = showNjk();
    expect(html).toContain('data-auto-reason="{{ complianceNaReason }}"');
    expect(html).toContain('>Not Applicable</option>');
    expect(html).toContain('>Skipped (give a reason)</option>');
    expect(html).toContain('data-auto-reason="{{ schemeNotEligibleReason }}"');
    expect(require('../../src/repositories/orderComplianceTaskRepository').NOT_APPLICABLE_REASON.length).toBeGreaterThanOrEqual(10);
    expect(require('../../src/repositories/orderExportBenefitSchemeRepository').NOT_ELIGIBLE_REASON.length).toBeGreaterThanOrEqual(10);
    // "+ Add a cost" opens and closes like "+ Add an expense".
    expect(html).toContain('<details class="fin-add-cost">\n        <summary class="small"><strong>+ Add a cost</strong></summary>');
  });

  it('CAFIN sits in its own box at the top of the order page, with no enable step', () => {
    // Owner request (2026-10-27): CAFIN can be generated whenever it is wanted.
    const html = showNjk();
    const box = html.indexOf('id="order-cafin"');
    expect(box).toBeGreaterThan(html.indexOf('id="order-detail-sidebar"'));
    expect(html).toContain('{% if canManageOrderFinancials %}\n      {# Owner request (2026-10-27): CAFIN');
    expect(html).toContain('Generate CAFIN now (PDF)');
    expect(html).not.toContain('Enable internal financial annexure for this order');
  });
});
