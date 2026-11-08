'use strict';
// Overlay injected into every page of the recording: actor badge (top right),
// caption pill, highlight ring, a smooth visible cursor and a click ripple.
// State lives in sessionStorage so it survives page navigations.

function overlayMain(brand, zoom) {
  const KEY = '__tut';
  // Zoom the page from the very first paint (the overlay styles follow at DOMContentLoaded).
  try { const z = document.createElement('style'); z.id = 'tut-zoom'; z.textContent = `body{zoom:${zoom}}.dev-server-banner,.alert-dev{display:none!important}`; document.documentElement.appendChild(z); } catch (e) { /* ignore */ }
  const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } };
  const save = (s) => { try { sessionStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* ignore */ } };

  function css() {
    return `
#tut-root{position:fixed;inset:0;pointer-events:none;z-index:2147483647;font-family:Poppins,system-ui,sans-serif}
#tut-ui{position:absolute;inset:0;zoom:${zoom}}
body{zoom:${zoom}}
#tut-badge{position:absolute;top:62px;right:12px;display:flex;align-items:center;gap:8px;padding:6px 14px 6px 8px;border-radius:999px;
  background:rgba(255,255,255,.96);box-shadow:0 4px 16px rgba(0,0,0,.18);font-size:13px;font-weight:600;color:${brand.ink};border:2px solid var(--tut-c,${brand.navy})}
#tut-badge .dot{width:26px;height:26px;border-radius:50%;background:var(--tut-c,${brand.navy});color:#fff;display:grid;place-items:center;font-size:12px;font-weight:700}
#tut-badge .sub{font-weight:400;color:#5a6070;font-size:11px;display:block;line-height:1}
#tut-cap{position:absolute;left:50%;transform:translateX(-50%);bottom:118px;max-width:70%;padding:8px 18px;border-radius:10px;
  background:${brand.navyStrong};color:#fff;font-size:15px;font-weight:500;box-shadow:0 6px 20px rgba(0,0,0,.25);border-left:5px solid ${brand.gold};
  transition:opacity .35s;opacity:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#tut-cap.on{opacity:1}
#tut-ring{position:absolute;border:3px solid ${brand.gold};border-radius:10px;box-shadow:0 0 0 6px rgba(201,150,43,.25),0 0 0 9999px rgba(0,16,48,.07);
  transition:all .35s ease;opacity:0}
#tut-ring.on{opacity:1}
#tut-cursor{position:absolute;left:0;top:0;width:34px;height:34px;transform:translate(-3px,-2px);transition:none;filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))}
.tut-ripple{position:absolute;width:14px;height:14px;margin:-7px 0 0 -7px;border-radius:50%;border:3px solid ${brand.gold};animation:tutRip .6s ease-out forwards}
@keyframes tutRip{to{transform:scale(3.4);opacity:0}}
.dev-server-banner{display:none!important}
.tut-dev-hidden{display:none!important}`;
  }

  function build() {
    if (!document.documentElement || document.getElementById('tut-root')) return;
    const st = document.createElement('style'); st.id = 'tut-style'; st.textContent = css();
    const root = document.createElement('div'); root.id = 'tut-root';
    root.innerHTML = '<div id="tut-ui"><div id="tut-badge" style="display:none"><span class="dot"></span><span><span class="who"></span><span class="sub"></span></span></div>'
      + '<div id="tut-cap"></div></div><div id="tut-ring"></div>'
      + '<svg id="tut-cursor" viewBox="0 0 24 24"><path d="M3 2 L3 20 L8 15.5 L11.5 22.5 L14.5 21 L11 14.2 L18 14 Z" fill="#fff" stroke="#111" stroke-width="1.6" stroke-linejoin="round"/></svg>';
    document.documentElement.appendChild(st);
    document.documentElement.appendChild(root);
    render(load());
  }

  function render(s) {
    const root = document.getElementById('tut-root'); if (!root) return;
    const b = root.querySelector('#tut-badge');
    if (s.actor) {
      b.style.display = 'flex'; b.style.setProperty('--tut-c', s.actor.color || brand.navy);
      b.querySelector('.dot').textContent = s.actor.initials || '';
      b.querySelector('.who').textContent = s.actor.label || '';
      b.querySelector('.sub').textContent = s.actor.sub || '';
    } else b.style.display = 'none';
    const c = root.querySelector('#tut-cap');
    if (s.caption) { c.textContent = s.caption; c.classList.add('on'); } else c.classList.remove('on');
    const cur = root.querySelector('#tut-cursor');
    cur.style.display = s.hideCursor ? 'none' : 'block';
    if (s.mx != null) { cur.style.left = s.mx + 'px'; cur.style.top = s.my + 'px'; }
  }

  window.__tut = {
    set(patch) { const s = Object.assign(load(), patch); save(s); build(); render(s); },
    ring(r) {
      build(); const el = document.getElementById('tut-ring'); if (!el) return;
      if (!r) { el.classList.remove('on'); return; }
      const p = 6; Object.assign(el.style, { left: (r.x - p) + 'px', top: (r.y - p) + 'px', width: (r.width + 2 * p) + 'px', height: (r.height + 2 * p) + 'px' });
      el.classList.add('on');
    },
  };

  document.addEventListener('mousemove', (e) => {
    const cur = document.getElementById('tut-cursor'); if (!cur) return;
    cur.style.left = e.clientX + 'px'; cur.style.top = e.clientY + 'px';
    const s = load(); s.mx = e.clientX; s.my = e.clientY; save(s);
  }, true);
  document.addEventListener('mousedown', (e) => {
    const root = document.getElementById('tut-root'); if (!root) return;
    const r = document.createElement('div'); r.className = 'tut-ripple'; r.style.left = e.clientX + 'px'; r.style.top = e.clientY + 'px';
    root.appendChild(r); setTimeout(() => r.remove(), 700);
  }, true);

  // Dev-only helpers (e.g. "Development only — your login code is 123456.") are hidden on camera:
  // the code is kept in window.__tutDevCode so the script can type it, as if read from the e-mail.
  function hideDev() {
    document.querySelectorAll('.alert').forEach((el) => {
      const text = el.textContent || '';
      if (!/Development only/i.test(text)) return;
      const code = text.match(/code is (\d{6})/); if (code) window.__tutDevCode = code[1];
      const link = text.match(/link: (\S+)/); if (link) window.__tutDevLink = link[1];
      if (/^\s*Development only/i.test(text)) { el.classList.add('tut-dev-hidden'); return; }
      // Keep the real message, drop the dev-only tail.
      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      let n; let cut = false;
      while ((n = walker.nextNode())) {
        if (cut) { n.textContent = ''; continue; }
        const i = n.textContent.search(/\s*Development only/i);
        if (i >= 0) { n.textContent = n.textContent.slice(0, i); cut = true; }
      }
    });
  }
  const ready = () => { hideDev(); build(); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
}

function initScript(brand, zoom = 1) {
  return `(${overlayMain.toString()})(${JSON.stringify(brand)}, ${Number(zoom)});`;
}

module.exports = { initScript };
