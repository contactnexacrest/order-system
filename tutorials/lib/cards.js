'use strict';
// Full-screen title, chapter and end cards, shown with page.setContent().
// Fonts and logo are embedded so cards render the same on any page.
const fs = require('fs');
const path = require('path');
const cfg = require('./config');

let fontCss = null;
function fonts() {
  if (fontCss) return fontCss;
  fontCss = [400, 500, 600, 700].map((w) => {
    const f = path.join(cfg.FONTS, `poppins-${w}.woff2`);
    const b64 = fs.existsSync(f) ? fs.readFileSync(f).toString('base64') : '';
    return `@font-face{font-family:Poppins;font-weight:${w};src:url(data:font/woff2;base64,${b64}) format('woff2')}`;
  }).join('\n');
  return fontCss;
}
function logo() {
  return fs.existsSync(cfg.LOGO) ? `data:image/jpeg;base64,${fs.readFileSync(cfg.LOGO).toString('base64')}` : '';
}
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function shell(body, extraCss = '') {
  const B = cfg.BRAND;
  return `<!doctype html><html><head><meta charset="utf-8"><style>${fonts()}
*{box-sizing:border-box;margin:0}
html{zoom:${cfg.VIEW.zoom || 1}}
html,body{width:100%;height:100%;overflow:hidden}
/* A card never takes the app overlay (its body zoom, badge, caption): it can be injected for a moment while the next card loads. */
body{zoom:1!important}#tut-root{display:none!important}
body{font-family:Poppins,system-ui,sans-serif;color:#fff;background:radial-gradient(ellipse at 20% 10%,#0b46c4 0%,${B.navy} 38%,${B.navyStrong} 100%);display:flex;align-items:center;justify-content:center}
.wrap{width:1080px;position:relative}
.logo{width:74px;height:74px;border-radius:16px;background:#fff center/cover no-repeat;box-shadow:0 8px 30px rgba(0,0,0,.35)}
.kicker{font-size:17px;letter-spacing:.18em;text-transform:uppercase;color:${B.gold};font-weight:600}
h1{font-size:58px;line-height:1.12;font-weight:700;margin:14px 0 18px}
.sub{font-size:23px;color:#dbe4ff;font-weight:400;max-width:900px;line-height:1.45}
.bar{height:5px;width:120px;background:${B.gold};border-radius:3px;margin:26px 0}
.foot{position:fixed;bottom:34px;left:0;right:0;text-align:center;font-size:14px;color:#9fb3e8;letter-spacing:.06em}
.in{opacity:0;transform:translateY(18px);animation:in .7s ease forwards}
.d1{animation-delay:.15s}.d2{animation-delay:.35s}.d3{animation-delay:.55s}.d4{animation-delay:.75s}.d5{animation-delay:.95s}
@keyframes in{to{opacity:1;transform:none}}
.deco{position:fixed;right:-160px;top:-160px;width:520px;height:520px;border-radius:50%;border:60px solid rgba(201,150,43,.12)}
.deco2{position:fixed;left:-120px;bottom:-200px;width:420px;height:420px;border-radius:50%;border:40px solid rgba(255,255,255,.05)}
${extraCss}</style></head><body><div class="deco"></div><div class="deco2"></div>${body}
<div class="foot">NexaCrest International · Tutorials · demo data only</div></body></html>`;
}

function titleCard({ code, group, title, subtitle, minutes }) {
  return shell(`<div class="wrap">
  <div class="in" style="display:flex;align-items:center;gap:18px"><div class="logo" style="background-image:url(${logo()})"></div>
  <div class="kicker">${esc(group)} · Video ${esc(code)}</div></div>
  <h1 class="in d1">${esc(title)}</h1>
  <div class="bar in d2"></div>
  <div class="sub in d3">${esc(subtitle)}</div>
  ${minutes ? `<div class="sub in d4" style="margin-top:22px;font-size:17px;color:#b9c8f2">About ${esc(minutes)} minutes</div>` : ''}
</div>`);
}

function chapterCard({ number, title, subtitle }) {
  return shell(`<div class="wrap">
  <div class="kicker in">Part ${esc(number)}</div>
  <h1 class="in d1" style="font-size:52px">${esc(title)}</h1>
  <div class="bar in d2"></div>
  ${subtitle ? `<div class="sub in d3">${esc(subtitle)}</div>` : ''}
</div>`);
}

function listCard({ kicker, title, items = [], next }) {
  const lis = items.map((t, i) => `<li class="in d${Math.min(i + 2, 5)}"><span class="tick">✓</span>${esc(t)}</li>`).join('');
  return shell(`<div class="wrap">
  <div class="kicker in">${esc(kicker || 'Recap')}</div>
  <h1 class="in d1" style="font-size:46px">${esc(title)}</h1>
  <ul class="list">${lis}</ul>
  ${next ? `<div class="next in d5">Up next → <b>${esc(next)}</b></div>` : ''}
</div>`, `.list{list-style:none;padding:0;margin:26px 0 0}
.list li{font-size:24px;margin:0 0 16px;display:flex;gap:16px;align-items:flex-start;line-height:1.4}
.tick{flex:0 0 34px;height:34px;border-radius:50%;background:${cfg.BRAND.gold};color:${cfg.BRAND.navyStrong};display:grid;place-items:center;font-weight:700;font-size:18px}
.next{margin-top:34px;font-size:20px;color:#dbe4ff;border-top:1px solid rgba(255,255,255,.2);padding-top:22px}`);
}


// An e-mail as the buyer receives it (the app's own wording), for steps that start in the inbox.
function emailCard({ from = 'NexaCrest International <info@email.com>', to, subject, lines = [], attach = [], link }) {
  const body = lines.map((t, i) => `<p class="in d${Math.min(i + 2, 5)}">${esc(t)}</p>`).join('');
  const att = attach.map((a) => `<span class="att">&#128206; ${esc(a)}</span>`).join('');
  return shell(`<div class="wrap">
  <div class="kicker in">In the buyer's inbox</div>
  <div class="mail in d1">
    <div class="hdr"><div><b>From</b> ${esc(from)}</div><div><b>To</b> ${esc(to)}</div><div class="subj">${esc(subject)}</div></div>
    <div class="body">${body}${link ? `<p class="lnk in d4">${esc(link)}</p>` : ''}${att ? `<div class="atts in d5">${att}</div>` : ''}</div>
  </div>
</div>`, `.mail{margin-top:22px;background:#fff;color:#1f2937;border-radius:14px;box-shadow:0 16px 50px rgba(0,0,0,.35);overflow:hidden}
.hdr{background:#f3f5fb;padding:18px 28px;font-size:17px;line-height:1.7;color:#4b5563;border-bottom:1px solid #e5e7eb}
.hdr b{color:#111827;display:inline-block;width:58px}
.subj{font-size:24px;font-weight:600;color:#111827;margin-top:6px}
.body{padding:22px 28px 26px;font-size:20px;line-height:1.5}
.body p{margin:0 0 12px}
.lnk{color:#1d4ed8;text-decoration:underline;word-break:break-all}
.atts{margin-top:14px;display:flex;gap:12px;flex-wrap:wrap}
.att{background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:6px 12px;font-size:17px;color:#1e3a8a}`);
}

module.exports = { titleCard, chapterCard, listCard, emailCard };
