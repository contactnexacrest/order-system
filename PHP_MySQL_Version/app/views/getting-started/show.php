<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Getting Started — NexaCrest Export Operations</title>
<link rel="icon" href="/assets/img/favicon.ico?v=2" sizes="any">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
/* Getting Started — a standalone first-day tour, not wrapped in the usual
   app shell (it has its own sidebar), but built entirely on app.css's own
   tokens/fonts so it still looks like part of the same application rather
   than a bolted-on page. Every class here is "gs-" prefixed to avoid any
   collision with app.css's own selectors. */
*{box-sizing:border-box;}
html,body{margin:0; background:var(--bg); color:var(--ink);}
body{font-size:15.5px; line-height:1.6;}
.gs-shell{display:flex; min-height:100vh;}

.gs-rail{
  width:252px; flex:0 0 252px; background:var(--navy-strong); color:#eef2fb;
  position:sticky; top:0; align-self:flex-start; height:100vh; overflow-y:auto;
  padding:22px 16px 90px; border-right:1px solid rgba(255,255,255,.06);
}
.gs-rail-brand{display:flex; align-items:center; gap:10px; padding:0 6px 18px; border-bottom:1px solid rgba(255,255,255,.1); margin-bottom:16px;}
.gs-rail-mark{width:32px; height:32px; border-radius:8px; overflow:hidden; flex:0 0 auto; background:#fff;}
.gs-rail-mark img{width:100%; height:100%; object-fit:cover;}
.gs-rail-brand b{font-size:15px; letter-spacing:.01em;}
.gs-rail-brand span{display:block; font-size:10.5px; color:#9fb0d8; letter-spacing:.06em; text-transform:uppercase;}
.gs-rail nav a{
  display:flex; align-items:center; gap:9px; padding:8px 9px; border-radius:8px;
  color:#c7d1ea; text-decoration:none; font-size:13.3px; font-weight:500;
  margin-bottom:1px; transition:background .12s, color .12s;
}
.gs-rail nav a .n{font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11px; color:#6d7aa0; width:18px; flex:0 0 auto;}
.gs-rail nav a:hover{background:rgba(255,255,255,.06); color:#fff;}
.gs-rail nav a.active{background:var(--gold); color:#2b1c00; font-weight:600;}
.gs-rail nav a.active .n{color:#2b1c00;}
.gs-rail-progress{margin-top:18px; padding:12px; border-radius:10px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08);}
.gs-rail-progress .lbl{font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#9fb0d8; margin-bottom:6px; display:flex; justify-content:space-between;}
.gs-rail-progress .bar{height:6px; border-radius:99px; background:rgba(255,255,255,.12); overflow:hidden;}
.gs-rail-progress .bar i{display:block; height:100%; background:var(--gold); width:0%; transition:width .25s ease;}
.gs-rail-back{display:block; margin-top:14px; font-size:12px; color:#9fb0d8; text-decoration:none;}
.gs-rail-back:hover{color:#fff;}

.gs-main{flex:1 1 auto; min-width:0;}
.gs-wrap{max-width:860px; margin:0 auto; padding:52px 24px 100px;}

.gs-mobile-nav{display:none;}
@media (max-width: 880px){
  .gs-rail{display:none;}
  .gs-mobile-nav{
    display:flex; gap:6px; overflow-x:auto; position:sticky; top:0; z-index:20;
    background:var(--navy-strong); padding:10px 12px; -webkit-overflow-scrolling:touch;
  }
  .gs-mobile-nav a{flex:0 0 auto; color:#c7d1ea; text-decoration:none; font-size:12.5px; font-weight:600; padding:7px 11px; border-radius:99px; background:rgba(255,255,255,.07); white-space:nowrap;}
  .gs-mobile-nav a.active{background:var(--gold); color:#2b1c00;}
  .gs-wrap{padding-top:26px;}
}

.gs-hero{margin-bottom:44px;}
.gs-eyebrow{display:inline-flex; align-items:center; gap:7px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11.5px; letter-spacing:.08em; text-transform:uppercase; color:var(--gold-strong); background:var(--gold-soft); padding:5px 11px; border-radius:99px; margin-bottom:16px;}
.gs-eyebrow i{width:6px; height:6px; border-radius:50%; background:var(--gold-strong);}
.gs-hero h1{font-size:clamp(28px,5vw,40px); font-weight:800; line-height:1.1; margin:0 0 7px;}
.gs-hero .gs-who{font-size:14px; color:var(--ink-soft); margin-bottom:16px;}
.gs-hero .gs-lede{font-size:17px; color:var(--ink-soft); max-width:62ch; margin-bottom:20px;}
.gs-hero-meta{display:flex; flex-wrap:wrap; gap:10px;}
.gs-chip{display:flex; align-items:center; gap:7px; background:var(--surface); border:1px solid var(--line); padding:8px 13px; border-radius:10px; font-size:13px; color:var(--ink-soft); font-weight:500;}
.gs-chip b{color:var(--ink);}

.gs-section{margin-bottom:52px; scroll-margin-top:24px;}
.gs-sec-head{display:flex; align-items:baseline; gap:12px; margin-bottom:16px; padding-bottom:12px; border-bottom:2px solid var(--line);}
.gs-sec-head .num{font-family:ui-monospace,Menlo,Consolas,monospace; font-size:13px; color:var(--gold-strong); background:var(--gold-soft); width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex:0 0 auto; font-weight:600;}
.gs-sec-head h2{font-size:21px; font-weight:700; margin:0;}
.gs-sec-sub{color:var(--ink-soft); font-size:14px; margin-top:2px;}

.gs-callout{border-radius:var(--radius); padding:15px 18px; border:1px solid var(--line); background:#fff; margin:16px 0;}
.gs-callout.tip{border-color:var(--gold-soft); background:var(--gold-soft);}
.gs-callout.tip b{color:var(--gold-strong);}
.gs-callout p{margin:0 0 .6em;}
.gs-callout p:last-child{margin-bottom:0;}

.gs-shot{border-radius:var(--radius); overflow:hidden; border:1px solid var(--line); box-shadow:0 10px 28px -16px rgba(1,48,144,.35); margin:18px 0 8px;}
.gs-shot img{width:100%; display:block;}
.gs-shot-cap{font-size:12.5px; color:var(--ink-soft); text-align:center; margin-bottom:22px;}

.gs-pipeline{display:flex; gap:0; overflow-x:auto; padding:6px 2px 14px; margin:18px 0 6px;}
.gs-pstep{flex:0 0 150px; position:relative; padding:0 10px 0 0;}
.gs-pstep:not(:last-child)::after{content:""; position:absolute; top:15px; right:0; width:10px; height:2px; background:var(--line);}
.gs-pstep .dot{width:30px; height:30px; border-radius:50%; background:var(--navy); color:#fff; font-family:ui-monospace,Menlo,Consolas,monospace; font-weight:600; font-size:13px; display:flex; align-items:center; justify-content:center; margin-bottom:9px;}
.gs-pstep .t{font-size:12.8px; font-weight:600; line-height:1.3; margin-bottom:4px; color:var(--ink);}
.gs-pstep .d{font-size:11.8px; color:var(--ink-soft); line-height:1.35;}

.gs-compare{display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-top:16px;}
@media (max-width:620px){.gs-compare{grid-template-columns:1fr;}}
.gs-compare h3{font-size:14.5px; margin:0 0 8px; display:flex; align-items:center; gap:7px;}
.gs-pill{font-size:11px; font-family:ui-monospace,Menlo,Consolas,monospace; padding:2px 8px; border-radius:99px; background:var(--success-soft); color:var(--success); font-weight:600; text-transform:uppercase; letter-spacing:.03em;}
.gs-pill.gold{background:var(--gold-soft); color:var(--gold-strong);}

.gs-navmap{display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; margin-top:16px;}
.gs-navmap .grp{border:1px solid var(--line); border-radius:12px; padding:14px 16px; background:var(--surface);}
.gs-navmap .grp h4{font-size:13.5px; margin:0 0 8px; color:var(--navy);}
.gs-navmap .grp ul{margin:0; padding:0; list-style:none;}
.gs-navmap .grp li{font-size:12.8px; color:var(--ink-soft); padding:3px 0; border-bottom:1px dashed var(--line);}
.gs-navmap .grp li:last-child{border-bottom:none;}
@media (max-width:620px){.gs-navmap{grid-template-columns:1fr;}}

.gs-reftable{width:100%; border-collapse:collapse; margin-top:14px; font-size:13.6px;}
.gs-reftable th{text-align:left; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); padding:8px 12px; border-bottom:2px solid var(--line);}
.gs-reftable td{padding:11px 12px; border-bottom:1px solid var(--line); vertical-align:top;}
.gs-reftable tr:hover td{background:var(--navy-soft);}
.gs-reftable .go{font-weight:600; color:var(--navy); white-space:nowrap;}

.gs-filterbar{display:flex; flex-wrap:wrap; gap:7px; margin:14px 0 2px;}
.gs-fchip{font-size:12px; font-weight:600; padding:6px 12px; border-radius:99px; border:1px solid var(--line); background:var(--surface); color:var(--ink-soft); cursor:pointer;}
.gs-fchip.on{background:var(--navy); color:#fff; border-color:var(--navy);}

.gs-checklist{display:flex; flex-direction:column; gap:8px; margin-top:16px;}
.gs-citem{display:flex; align-items:flex-start; gap:12px; padding:13px 15px; border:1px solid var(--line); border-radius:11px; background:var(--surface); cursor:pointer; transition:background .12s, border-color .12s;}
.gs-citem:hover{border-color:var(--gold);}
.gs-citem.done{background:var(--success-soft); border-color:var(--success);}
.gs-citem .box{width:20px; height:20px; border-radius:6px; border:2px solid var(--line); flex:0 0 auto; margin-top:1px; display:flex; align-items:center; justify-content:center; transition:background .12s,border-color .12s;}
.gs-citem.done .box{background:var(--success); border-color:var(--success);}
.gs-citem .box svg{opacity:0; transition:opacity .12s;}
.gs-citem.done .box svg{opacity:1;}
.gs-citem .txt b{display:block; font-size:14px; font-weight:600; margin-bottom:2px; color:var(--ink);}
.gs-citem.done .txt b{text-decoration:line-through; color:var(--ink-soft);}
.gs-citem .txt span{font-size:12.6px; color:var(--ink-soft);}
.gs-citem.gs-static{cursor:default;}
.gs-citem.gs-static:hover{border-color:var(--line);}

details.gs-conv{border:1px solid var(--line); border-radius:11px; margin-bottom:8px; background:var(--surface); overflow:hidden;}
details.gs-conv summary{padding:13px 16px; font-weight:600; font-size:14px; cursor:pointer; list-style:none; display:flex; align-items:center; gap:10px; color:var(--ink);}
details.gs-conv summary::-webkit-details-marker{display:none;}
details.gs-conv summary::before{content:"+"; font-family:ui-monospace,Menlo,Consolas,monospace; color:var(--gold-strong); font-weight:700; width:16px;}
details.gs-conv[open] summary::before{content:"\2013";}
details.gs-conv .gs-body{padding:0 16px 15px 42px; font-size:13.6px; color:var(--ink-soft);}

.gs-foot{margin-top:66px; padding-top:22px; border-top:1px solid var(--line); font-size:12.5px; color:var(--ink-soft); display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;}
.gs-foot a{color:var(--navy);}
</style>
</head>
<body>

<div class="gs-shell">
  <aside class="gs-rail">
    <div class="gs-rail-brand">
      <span class="gs-rail-mark"><img src="/assets/img/logo.jpg" alt=""></span>
      <div><b>NexaCrest</b><span>Getting Started</span></div>
    </div>
    <nav id="gs-railnav">
      <a href="#welcome"><span class="n">00</span>Welcome</a>
      <a href="#pipeline"><span class="n">01</span>The Big Picture</a>
      <a href="#login"><span class="n">02</span>Logging In</a>
      <a href="#navigate"><span class="n">03</span>Finding Your Way</a>
      <a href="#dashboard"><span class="n">04</span>Your Dashboard</a>
      <a href="#views"><span class="n">05</span>Standard vs Kanban</a>
      <a href="#workflow"><span class="n">06</span>A Typical Day</a>
      <a href="#client"><span class="n">07</span>The Buyer's Side</a>
      <a href="#conventions"><span class="n">08</span>Conventions</a>
      <a href="#reference"><span class="n">09</span>Quick Reference</a>
      <a href="#checklist"><span class="n">10</span>Your First Week</a>
    </nav>
    <div class="gs-rail-progress">
      <div class="lbl"><span>First-week progress</span><span id="gs-pct">0%</span></div>
      <div class="bar"><i id="gs-pctbar"></i></div>
    </div>
    <a href="/" class="gs-rail-back">&larr; Back to Dashboard</a>
  </aside>

  <div class="gs-mobile-nav" id="gs-mobilenav">
    <a href="#welcome">Welcome</a>
    <a href="#pipeline">Big Picture</a>
    <a href="#login">Login</a>
    <a href="#navigate">Navigate</a>
    <a href="#dashboard">Dashboard</a>
    <a href="#views">Views</a>
    <a href="#workflow">Workflow</a>
    <a href="#client">Client Side</a>
    <a href="#conventions">Conventions</a>
    <a href="#reference">Reference</a>
    <a href="#checklist">Checklist</a>
  </div>

  <main class="gs-main">
    <div class="gs-wrap">

      <section id="welcome" class="gs-section gs-hero">
        <span class="gs-eyebrow"><i></i>Day one, start here</span>
        <h1>Getting Started with the Order System</h1>
        <?php if (!empty($userName)): ?><p class="gs-who">Signed in as <b style="color:var(--ink)"><?= htmlspecialchars($userName) ?></b>.</p><?php endif; ?>
        <p class="gs-lede">You've just been handed a login and a very real job: run export orders without losing track of a single document or payment. This isn't the full manual — it's the ten-minute tour that makes the full manual make sense. Detail lives in the SOP library inside the app; this page is the map to it.</p>
        <div class="gs-hero-meta">
          <span class="gs-chip"><b>9</b>&nbsp;pipeline stages</span>
          <span class="gs-chip"><b>2</b>&nbsp;ways to view your work</span>
          <span class="gs-chip"><b>1</b>&nbsp;portal the buyer sees too</span>
          <span class="gs-chip">Read time: <b>~8 min</b></span>
        </div>
      </section>

      <section id="pipeline" class="gs-section">
        <div class="gs-sec-head"><span class="num">01</span><div><h2>The Big Picture</h2><div class="gs-sec-sub">Every order, no matter what it is or who it's for, moves through the same nine stages</div></div></div>
        <p>You never have to memorize this — every order's own page shows it at the top, lit up to show exactly where that order stands. But knowing the shape of it now will make every screen you open afterward click into place faster.</p>
        <div class="gs-pipeline">
          <div class="gs-pstep"><div class="dot">1</div><div class="t">Enquiry &amp; Quotation</div><div class="d">A buyer asks; we quote.</div></div>
          <div class="gs-pstep"><div class="dot">2</div><div class="t">Buyer PO</div><div class="d">They send their own PO back.</div></div>
          <div class="gs-pstep"><div class="dot">3</div><div class="t">Proforma Invoice</div><div class="d">We invoice; advance payment clears.</div></div>
          <div class="gs-pstep"><div class="dot">4</div><div class="t">Order Confirmation</div><div class="d">We confirm; they acknowledge.</div></div>
          <div class="gs-pstep"><div class="dot">5</div><div class="t">Supplier PO</div><div class="d">We order from our own supplier.</div></div>
          <div class="gs-pstep"><div class="dot">6</div><div class="t">Freight Payment</div><div class="d">Paid — auto-skipped on FOB orders.</div></div>
          <div class="gs-pstep"><div class="dot">7</div><div class="t">Packing &amp; BL</div><div class="d">Packed, shipped, BL issued.</div></div>
          <div class="gs-pstep"><div class="dot">8</div><div class="t">CI &amp; Balance</div><div class="d">Balance invoiced; it clears.</div></div>
          <div class="gs-pstep"><div class="dot">9</div><div class="t">Despatch &amp; Closure</div><div class="d">Final docs out; order settled.</div></div>
        </div>
        <div class="gs-callout"><p><b>Alongside</b> this main line, a handful of things can happen at any point — a buyer wanting to cancel (Disputes), a process complaint with no cancel intent (Complaints), a change to payment terms (Amendments), a repeat order. Each has its own chapter in the full SOP. Not now — just know they exist.</p></div>
      </section>

      <section id="login" class="gs-section">
        <div class="gs-sec-head"><span class="num">02</span><div><h2>Logging In</h2><div class="gs-sec-sub">Same screen for everyone — what you see after depends on your account, not the URL</div></div></div>
        <div class="gs-shot"><img alt="Staff sign-in screen" src="/sop-assets/login_screen.png"></div>
        <div class="gs-shot-cap">The staff sign-in screen — the only door in</div>
        <div class="gs-callout tip">
          <p><b>Three things that catch people out the first time:</b></p>
          <p>1. Your very first login <b>forces a password change</b> — whatever you were given to start, you're not meant to keep.</p>
          <p>2. A second step may ask for a <b>6-digit code emailed to you</b> — this is two-factor authentication, and it isn't optional.</p>
          <p>3. <b>Forgot your password?</b> The link is right there. You'll see the same message whether or not the email matches a real account — that's deliberate, not a bug.</p>
        </div>
      </section>

      <section id="navigate" class="gs-section">
        <div class="gs-sec-head"><span class="num">03</span><div><h2>Finding Your Way Around</h2><div class="gs-sec-sub">One sidebar, every page, always in the same place</div></div></div>
        <p>Everything lives behind the dark navy strip on the left of the main app (not this page's own rail — the real one). It's worth thirty seconds here before you click anything else.</p>
        <div class="gs-navmap">
          <div class="grp"><h4>Always visible</h4><ul><li>Dashboard</li><li>Reference Library</li><li>SOP / Operations Guide</li><li>My Reviews</li><li>Notifications &amp; Account</li></ul></div>
          <div class="grp"><h4>Operations</h4><ul><li>Clients</li><li>Orders</li><li>Intake Review queues</li><li>Reorder Requests</li><li>Disputes</li><li>Complaints</li></ul></div>
          <div class="grp"><h4>Insights</h4><ul><li>Reports</li><li>Audit Log</li></ul></div>
          <div class="grp"><h4>Admin (if you hold it)</h4><ul><li>Company Settings</li><li>Users &amp; Permissions</li><li>UI Templates</li><li>…and more</li></ul></div>
        </div>
        <div class="gs-callout"><p><b>You will not see all of this.</b> What's in your sidebar depends entirely on your role. If a later chapter mentions a screen you can't find, that's almost always a permission you don't hold — not something broken. Ask whoever manages Roles &amp; Permissions.</p></div>
      </section>

      <section id="dashboard" class="gs-section">
        <div class="gs-sec-head"><span class="num">04</span><div><h2>Your Dashboard</h2><div class="gs-sec-sub">What you land on every time — built to answer "what needs me, right now"</div></div></div>
        <div class="gs-shot"><img alt="Staff dashboard with full sidebar" src="/sop-assets/staff_dashboard.png"></div>
        <div class="gs-shot-cap">Dashboard — search, approvals, active orders, overdue items, recent activity</div>
        <ul style="padding-left:18px; color:var(--ink-soft); font-size:14px; line-height:1.9; margin:0;">
          <li><b style="color:var(--ink)">Search</b> — jump straight to a client or order by reference.</li>
          <li><b style="color:var(--ink)">My Approvals</b> — reviews, email sends, amendments waiting specifically on you.</li>
          <li><b style="color:var(--ink)">Active Orders by Stage</b> — everything open, grouped by where it sits in the 9 stages.</li>
          <li><b style="color:var(--ink)">Overdue Payments &amp; Compliance Warnings</b> — anything past a deadline.</li>
          <li><b style="color:var(--ink)">Recent Activity</b> — what happened while you were away.</li>
        </ul>
      </section>

      <section id="views" class="gs-section">
        <div class="gs-sec-head"><span class="num">05</span><div><h2>Standard vs Kanban</h2><div class="gs-sec-sub">A personal preference, set once on your Account page — set it, don't overthink it</div></div></div>
        <p>Almost every list of orders (and a few other queues) can be viewed two ways. Same records, same permissions — just a different shape.</p>
        <div class="gs-compare">
          <div class="card">
            <h3>Standard <span class="gs-pill">default</span></h3>
            <div class="gs-shot" style="margin-top:10px;"><img alt="Orders — Standard view" src="/sop-assets/orders_standard.png"></div>
            <p style="font-size:13px; color:var(--ink-soft); margin-top:8px; margin-bottom:0;">A card for every order, with a quick filter row above it.</p>
          </div>
          <div class="card">
            <h3>Kanban <span class="gs-pill gold">visual</span></h3>
            <div class="gs-shot" style="margin-top:10px;"><img alt="Orders — Kanban board view" src="/sop-assets/kanban_orders.png"></div>
            <p style="font-size:13px; color:var(--ink-soft); margin-top:8px; margin-bottom:0;">Same orders, one column per stage — no drag-and-drop needed.</p>
          </div>
        </div>
      </section>

      <section id="workflow" class="gs-section">
        <div class="gs-sec-head"><span class="num">06</span><div><h2>A Typical Day, Start to Finish</h2><div class="gs-sec-sub">Putting the pieces together</div></div></div>
        <div class="gs-checklist">
          <div class="gs-citem gs-static"><div class="box" style="background:var(--navy);border-color:var(--navy)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>1. A new enquiry comes in</b><span>Create the Client, then the Order, from the Clients screen.</span></div></div>
          <div class="gs-citem gs-static"><div class="box" style="background:var(--navy);border-color:var(--navy)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>2. The order moves through its 9 stages</b><span>One action at a time — each stage's own chapter tells you exactly what to click.</span></div></div>
          <div class="gs-citem gs-static"><div class="box" style="background:var(--navy);border-color:var(--navy)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>3. The system generates documents for you</b><span>Quotation, Proforma Invoice, Packing List and more — you review and approve before anything reaches the buyer.</span></div></div>
          <div class="gs-citem gs-static"><div class="box" style="background:var(--navy);border-color:var(--navy)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>4. The buyer has their own portal the whole time</b><span>Downloads, uploads, payment reports, a direct conversation thread — without an email back-and-forth for the basics.</span></div></div>
          <div class="gs-citem gs-static"><div class="box" style="background:var(--navy);border-color:var(--navy)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>5. The order closes — in two steps, not one</b><span>Documents sent, then fully settled. Not the same moment; see Stage 9 once you're there.</span></div></div>
        </div>
      </section>

      <section id="client" class="gs-section">
        <div class="gs-sec-head"><span class="num">07</span><div><h2>The Buyer's Side of Things</h2><div class="gs-sec-sub">A much simpler view of the same order</div></div></div>
        <p>The client sees no stages, no internal detail — just what concerns them. You won't usually work inside this view, but it helps to have seen it once: whatever you send or confirm is exactly what shows up here, nothing more.</p>
        <div class="gs-shot"><img alt="Client portal — My Orders, grouped by status" src="/sop-assets/portal_dashboard.png"></div>
        <div class="gs-shot-cap">Client portal — grouped by status, in the buyer's own words</div>
      </section>

      <section id="conventions" class="gs-section">
        <div class="gs-sec-head"><span class="num">08</span><div><h2>Conventions You'll Notice Everywhere</h2><div class="gs-sec-sub">Small habits that repeat across almost every screen</div></div></div>
        <details class="gs-conv" open><summary>A short grey line under most headings</summary><div class="gs-body">Tells you what that section is for and when to use it. Read it once — you probably won't need to again.</div></details>
        <details class="gs-conv"><summary>"To Be Confirmed" is always spelled out</summary><div class="gs-body">Never abbreviated, on screen or on documents — so nobody has to guess what an unfamiliar three-letter shorthand means.</div></details>
        <details class="gs-conv"><summary>Gold highlights mean "needs your attention"</summary><div class="gs-body">A pending approval, a notice, a badge count — the same color, used consistently, across the whole app.</div></details>
        <details class="gs-conv"><summary>Everything that matters is logged</summary><div class="gs-body">Staff actions, client actions, status changes — all in the Audit Log. Genuinely useful for "who did this and when," not just a compliance checkbox.</div></details>
        <details class="gs-conv"><summary>Notifications fire automatically</summary><div class="gs-body">For almost everything — a client action, an approval waiting on you, a deadline coming up. The bell icon is built to be worth checking.</div></details>
      </section>

      <section id="reference" class="gs-section">
        <div class="gs-sec-head"><span class="num">09</span><div><h2>Quick Reference</h2><div class="gs-sec-sub">"I need to…" — filter by what you're trying to do</div></div></div>
        <div class="gs-filterbar" id="gs-filterbar">
          <span class="gs-fchip on" data-f="all">All</span>
          <span class="gs-fchip" data-f="orders">Orders</span>
          <span class="gs-fchip" data-f="issues">Disputes &amp; Complaints</span>
          <span class="gs-fchip" data-f="admin">Admin &amp; Reports</span>
        </div>
        <table class="gs-reftable" id="gs-reftable">
          <tr data-cat="orders"><th>If you need to…</th><th>Go to…</th><th>Chapter</th></tr>
          <tr data-cat="orders"><td>Set up a new buyer and order</td><td class="go"><a href="/clients">Clients</a> → create client → create order</td><td><a href="/sop/01-stage1-enquiry-quotation">Ch. 1</a></td></tr>
          <tr data-cat="orders"><td>Check where an order stands</td><td class="go">Open the order — the 9-dot tracker is at the top</td><td>This page, §01</td></tr>
          <tr data-cat="orders"><td>Generate or re-send a document</td><td class="go">Inside the order, under Documents</td><td><a href="/sop/13-document-review-approval">Ch. 13</a></td></tr>
          <tr data-cat="admin"><td>See what's overdue or needs approval</td><td class="go"><a href="/">Dashboard</a></td><td>This page, §04</td></tr>
          <tr data-cat="issues"><td>Handle a buyer wanting to cancel</td><td class="go"><a href="/disputes">Disputes</a></td><td><a href="/sop/11-disputes">Ch. 11</a></td></tr>
          <tr data-cat="issues"><td>Handle a process complaint (no cancel intent)</td><td class="go"><a href="/complaints">Complaints</a></td><td><a href="/sop/19-complaints">Ch. 19</a></td></tr>
          <tr data-cat="orders"><td>Change payment terms after the PI is out</td><td class="go">Amendments, from the order page</td><td><a href="/sop/10-amendments">Ch. 10</a></td></tr>
          <tr data-cat="admin"><td>Run a report</td><td class="go"><a href="/reports">Insights → Reports</a></td><td><a href="/sop/14-reports">Ch. 14</a></td></tr>
          <tr data-cat="admin"><td>Check who did what, and when</td><td class="go"><a href="/audit-log">Insights → Audit Log</a></td><td>—</td></tr>
          <tr data-cat="admin"><td>Manage roles, users, or company settings</td><td class="go"><a href="/settings">Admin</a></td><td><a href="/sop/15-admin-settings">Ch. 15</a></td></tr>
          <tr data-cat="admin"><td>Rehearse something without touching real data</td><td class="go">Test Mode (if you have access)</td><td><a href="/sop/16-test-mode">Ch. 16</a></td></tr>
          <tr data-cat="admin"><td>CA / Accounting work</td><td class="go"><a href="/ca">CA / Accounting</a></td><td><a href="/sop/ca-01-overview">CA Overview</a></td></tr>
        </table>
      </section>

      <section id="checklist" class="gs-section">
        <div class="gs-sec-head"><span class="num">10</span><div><h2>Your First Week</h2><div class="gs-sec-sub">Tap each one off as you go — this is remembered on this device</div></div></div>
        <div class="gs-checklist" id="gs-checklist">
          <div class="gs-citem" data-id="c1"><div class="box"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>Log in and set your real password</b><span>Confirm you're receiving your 2FA codes too.</span></div></div>
          <div class="gs-citem" data-id="c2"><div class="box"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>Click through the whole sidebar once</b><span>Nothing breaks from looking — every screen here is safe to just open.</span></div></div>
          <div class="gs-citem" data-id="c3"><div class="box"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>Open one real order, top to bottom</b><span>Follow its 9-stage tracker from start to wherever it currently sits.</span></div></div>
          <div class="gs-citem" data-id="c4"><div class="box"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>Find and bookmark SOP / Operations Guide</b><span>It's this exact manual, reachable from inside the app any time.</span></div></div>
          <div class="gs-citem" data-id="c5"><div class="box"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>Set your preferred view on My Account</b><span>Standard or Kanban — whichever one clicked for you above.</span></div></div>
          <div class="gs-citem" data-id="c6"><div class="box"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>Read the chapter for your actual first task</b><span>Not every chapter — just the one you'll need this week.</span></div></div>
          <div class="gs-citem" data-id="c7"><div class="box"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div><div class="txt"><b>Ask about permissions before assuming something's missing</b><span>If a chapter mentions a screen you can't find, it's almost always a role setting.</span></div></div>
        </div>
      </section>

      <div class="gs-foot">
        <span>NexaCrest Order System — Getting Started</span>
        <span>The full manual: <a href="/sop">SOP / Operations Guide</a></span>
      </div>

    </div>
  </main>
</div>

<script>
(function(){
  try {
    var KEY = 'nexacrest-onboarding-checklist-v1';
    var items = Array.prototype.slice.call(document.querySelectorAll('#gs-checklist .gs-citem'));
    var pctEl = document.getElementById('gs-pct');
    var barEl = document.getElementById('gs-pctbar');
    function load(){ try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch(e){ return {}; } }
    function save(state){ try { localStorage.setItem(KEY, JSON.stringify(state)); } catch(e){ /* ignore */ } }
    function render(){
      var state = load();
      var done = 0;
      items.forEach(function(it){
        var on = !!state[it.dataset.id];
        it.classList.toggle('done', on);
        if (on) done++;
      });
      var pct = items.length ? Math.round(done / items.length * 100) : 0;
      if (pctEl) pctEl.textContent = pct + '%';
      if (barEl) barEl.style.width = pct + '%';
    }
    items.forEach(function(it){
      it.addEventListener('click', function(){
        var state = load();
        state[it.dataset.id] = !state[it.dataset.id];
        save(state);
        render();
      });
    });
    render();
  } catch(e) { /* storage unavailable — checklist still displays, just unchecked */ }

  try {
    var chips = Array.prototype.slice.call(document.querySelectorAll('#gs-filterbar .gs-fchip'));
    var rows = Array.prototype.slice.call(document.querySelectorAll('#gs-reftable tr[data-cat]'));
    chips.forEach(function(c){
      c.addEventListener('click', function(){
        chips.forEach(function(x){ x.classList.remove('on'); });
        c.classList.add('on');
        var f = c.dataset.f;
        rows.forEach(function(r){ r.style.display = (f === 'all' || r.dataset.cat === f) ? '' : 'none'; });
      });
    });
  } catch(e) {}

  try {
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('#gs-railnav a, #gs-mobilenav a'));
    var sections = navLinks.map(function(a){ return document.querySelector(a.getAttribute('href')); }).filter(Boolean);
    if ('IntersectionObserver' in window) {
      var obs = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
          if (entry.isIntersecting) {
            var id = '#' + entry.target.id;
            navLinks.forEach(function(a){ a.classList.toggle('active', a.getAttribute('href') === id); });
          }
        });
      }, { rootMargin: '-15% 0px -70% 0px', threshold: 0 });
      sections.forEach(function(s){ obs.observe(s); });
    }
    navLinks.forEach(function(a){
      a.addEventListener('click', function(e){
        var target = document.querySelector(a.getAttribute('href'));
        if (target) { e.preventDefault(); target.scrollIntoView({behavior:'smooth', block:'start'}); history.replaceState(null,'',a.getAttribute('href')); }
      });
    });
  } catch(e) {}
})();
</script>
</body>
</html>
