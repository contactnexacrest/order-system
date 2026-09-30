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

  it('freight section explains why it is a range', () => {
    const html = createNjk();
    expect(html).toContain('Why a range, not one figure');
    expect(html).toContain('order currency');
  });

  it('order show page wraps all sections in the Concept C sidebar layout', () => {
    const html = showNjk();
    expect(html).toContain('id="order-layout"');
    expect(html).toContain('id="order-sidebar"');
    expect(html).toContain('id="order-panels"');

    const panelsStart = html.indexOf('id="order-panels"');
    expect(panelsStart).toBeGreaterThan(-1);

    const sectionCountTotal = (html.match(/<div class="section"/g) || []).length;
    const afterPanels = html.slice(panelsStart);
    const sectionCountInsidePanels = (afterPanels.match(/<div class="section"/g) || []).length;
    expect(sectionCountInsidePanels).toBe(sectionCountTotal);
    expect(sectionCountTotal).toBeGreaterThanOrEqual(18);
  });

  it('order show page degrades gracefully without JavaScript', () => {
    const html = showNjk();
    const css = fs.readFileSync(cssPath, 'utf8');
    expect(css).toContain('.js-enabled .order-panels > .section{display:none;}');
    expect(html).toContain("layout.classList.add('js-enabled')");
  });

  it('order show page preserves the #order-updates anchor id', () => {
    const html = showNjk();
    expect(html).toContain('id="order-updates"');
  });
});
