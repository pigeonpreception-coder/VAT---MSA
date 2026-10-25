<style>
@verbatim
/* ==========================================================================
   VAT-MSA public site
   Self-contained on purpose: inline, no build step, no third-party requests
   (the app's CSP is default-src 'self'). Fonts are self-hosted from
   /site/fonts. Tokens first, then base, components, then page sections.
   ========================================================================== */

@font-face{font-family:"Newsreader";src:url(/site/fonts/newsreader-latin-wght-normal.woff2) format("woff2");font-weight:200 800;font-style:normal;font-display:swap}
@font-face{font-family:"Newsreader";src:url(/site/fonts/newsreader-latin-wght-italic.woff2) format("woff2");font-weight:200 800;font-style:italic;font-display:swap}
@font-face{font-family:"Inter";src:url(/site/fonts/inter-latin-wght-normal.woff2) format("woff2");font-weight:100 900;font-style:normal;font-display:swap}

:root{
  --paper:#FAF6EE; --paper-2:#F4EDDF; --paper-3:#EBE1CB;
  --line:#E3D9C2; --line-strong:#D2C5A6;
  --ink:#0B1B33; --ink-2:#112443; --ink-3:#1B3459; --ink-deep:#071426;
  --text:#18233A; --muted:#58637A;
  --on-ink:#E9EEF7; --on-ink-muted:#A5B3CA; --ink-line:rgba(255,255,255,.1);
  --gold:#E3A72F; --gold-2:#F2C25B; --gold-deep:#8F5E00; --gold-soft:#F8E8BE;
  --green:#0E7A5A; --green-soft:#DCF1E7;
  --red:#B23A2B; --red-soft:#F8DDD8;
  --blue:#2159B8; --blue-soft:#DCE8FA;

  --radius:16px; --radius-lg:24px; --radius-sm:10px;
  --shadow-sm:0 1px 2px rgba(11,27,51,.06),0 2px 8px -2px rgba(11,27,51,.08);
  --shadow:0 1px 2px rgba(11,27,51,.05),0 14px 34px -14px rgba(11,27,51,.24);
  --shadow-lg:0 2px 4px rgba(11,27,51,.06),0 36px 80px -30px rgba(11,27,51,.5);

  --wrap:1200px;
  --gutter:clamp(20px,4vw,40px);
  --section-y:clamp(72px,9vw,136px);
  --ease:cubic-bezier(.2,.7,.2,1);

  --font-serif:"Newsreader","Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
  --font-sans:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --font-mono:ui-monospace,"SFMono-Regular","Cascadia Mono",Consolas,Menlo,monospace;
}

/* ---------- Base ---------- */
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;text-size-adjust:100%;scroll-behavior:smooth;scroll-padding-top:88px}
body{margin:0;background:var(--paper);color:var(--text);font:400 1.0625rem/1.65 var(--font-sans);font-optical-sizing:auto;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeLegibility;overflow-x:clip}
h1,h2,h3,h4{margin:0;color:var(--ink);font-family:var(--font-serif);font-weight:400;letter-spacing:-.02em;text-wrap:balance}
p{margin:0;text-wrap:pretty}
ul,ol{margin:0;padding:0;list-style:none}
a{color:inherit;text-decoration:none}
img,svg{display:block;max-width:100%}
button{font:inherit;color:inherit}
:focus-visible{outline:3px solid var(--blue);outline-offset:3px;border-radius:6px}
.section--ink :focus-visible,.site-footer :focus-visible,.cert__head :focus-visible{outline-color:var(--gold)}
[id]{scroll-margin-top:96px}
::selection{background:var(--gold-soft);color:var(--ink)}

.sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.skip{position:absolute;left:12px;top:-64px;z-index:200;padding:10px 16px;border-radius:10px;background:var(--ink);color:#fff;font-weight:600;transition:top .2s}
.skip:focus{top:12px}
.wrap{width:min(var(--wrap),calc(100% - var(--gutter)*2));margin-inline:auto}
.wrap--narrow{width:min(820px,calc(100% - var(--gutter)*2));margin-inline:auto}
.icon{flex:none}
.mono{font-family:var(--font-mono);font-size:.92em;letter-spacing:0}

/* ---------- Typography helpers ---------- */
.eyebrow{display:inline-flex;align-items:center;gap:10px;font:700 .74rem/1 var(--font-sans);letter-spacing:.16em;text-transform:uppercase;color:var(--gold-deep)}
.eyebrow::before{content:"";width:22px;height:1.5px;background:currentColor;opacity:.7}
.section--ink .eyebrow,.on-ink .eyebrow{color:var(--gold)}
.h2{font-size:clamp(2rem,1.25rem + 2.9vw,3.5rem);line-height:1.06}
.h3{font-size:clamp(1.4rem,1.1rem + 1vw,1.9rem);line-height:1.15}
.lead{font-size:clamp(1.08rem,1rem + .35vw,1.27rem);line-height:1.6;color:var(--muted)}
.section--ink .lead,.on-ink .lead{color:var(--on-ink-muted)}
.section--ink h2,.section--ink h3,.on-ink h2,.on-ink h3{color:#fff}
.section-head{max-width:880px;margin-bottom:clamp(36px,5vw,64px)}
.section-head .eyebrow{margin-bottom:18px}
.section-head .lead{margin-top:18px}
.section-head--center{margin-inline:auto;text-align:center}
.section-head--center .eyebrow{justify-content:center}
em.accent{font-style:italic;color:var(--gold-deep)}
.section--ink em.accent,.on-ink em.accent{color:var(--gold)}

/* ---------- Sections ---------- */
.section{position:relative;padding-block:var(--section-y)}
.section--sand{background:var(--paper-2)}
.section--ink{background:var(--ink);color:var(--on-ink)}
.section--tight{padding-block:clamp(48px,6vw,88px)}

/* ---------- Buttons & links ---------- */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.6em;padding:.86em 1.4em;border:1px solid transparent;border-radius:999px;background:var(--ink);color:#fff;font:600 .96rem/1 var(--font-sans);letter-spacing:-.005em;white-space:nowrap;cursor:pointer;transition:transform .25s var(--ease),box-shadow .25s var(--ease),background .2s,color .2s,border-color .2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 14px 28px -14px rgba(11,27,51,.7)}
.btn:active{transform:translateY(0)}
.btn .icon{transition:transform .25s var(--ease)}
.btn:hover .icon{transform:translateX(3px)}
.btn--gold{background:var(--gold);color:var(--ink)}
.btn--gold:hover{background:var(--gold-2);box-shadow:0 14px 30px -14px rgba(227,167,47,.9)}
.btn--ghost{background:transparent;color:var(--ink);border-color:rgba(11,27,51,.22)}
.btn--ghost:hover{background:rgba(11,27,51,.05);box-shadow:none}
.btn--ghost-inverse{background:transparent;color:#fff;border-color:rgba(255,255,255,.3)}
.btn--ghost-inverse:hover{background:rgba(255,255,255,.08);box-shadow:none}
.btn--sm{padding:.7em 1.15em;font-size:.9rem}
.btn--lg{padding:1.05em 1.75em;font-size:1.02rem}
.btn--block{display:flex;width:100%}
.link-quiet{padding:.5em .7em;border-radius:8px;font-weight:600;font-size:.92rem;color:var(--ink);transition:background .2s}
.link-quiet:hover{background:rgba(11,27,51,.06)}
.link-arrow{display:inline-flex;align-items:center;gap:.45em;font-weight:600;color:var(--ink);border-bottom:1.5px solid var(--gold);padding-bottom:2px;transition:gap .25s var(--ease),border-color .2s}
.link-arrow:hover{gap:.75em;border-color:var(--ink)}
.section--ink .link-arrow{color:#fff}
.section--ink .link-arrow:hover{border-color:#fff}

/* ---------- Chips ---------- */
.chip{display:inline-flex;align-items:center;gap:.45em;padding:.38em .85em;border:1px solid var(--line);border-radius:999px;background:var(--paper-2);color:var(--ink);font:700 .68rem/1.15 var(--font-sans);letter-spacing:.07em;text-transform:uppercase;white-space:nowrap}
.chip .dot{width:7px;height:7px;border-radius:50%;background:currentColor}
.chip--ok{background:var(--green-soft);border-color:#BFE3D2;color:#0A5A42}
.chip--warn{background:var(--gold-soft);border-color:#EBCF8B;color:#6B4600}
.chip--bad{background:var(--red-soft);border-color:#EDBDB5;color:#8E2A1D}
.chip--info{background:var(--blue-soft);border-color:#BFD2F1;color:#173F86}
.chip--ink{background:var(--ink);border-color:var(--ink);color:#fff}
.chip--glass{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:#fff}

/* ---------- Brand ---------- */
.brand{display:inline-flex;align-items:center}
.logo{display:inline-flex;align-items:center;gap:10px;color:var(--ink)}
.logo__mark{flex:none;filter:drop-shadow(0 4px 10px rgba(11,27,51,.25))}
.logo__word{font:700 1.2rem/1 var(--font-sans);letter-spacing:-.025em}
.logo__dash{margin-inline:.03em;color:var(--gold-deep)}
.logo--inverse{color:#fff}
.logo--inverse .logo__dash{color:var(--gold)}

/* ---------- Header ---------- */
.site-header{position:sticky;top:0;z-index:60;background:rgba(250,246,238,.84);border-bottom:1px solid transparent;transition:border-color .25s,box-shadow .25s,background .25s}
@supports (backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px)){
  .site-header{-webkit-backdrop-filter:saturate(1.5) blur(16px);backdrop-filter:saturate(1.5) blur(16px)}
}
.site-header.is-scrolled{border-bottom-color:var(--line);box-shadow:0 10px 30px -22px rgba(11,27,51,.5)}
.site-header__inner{display:flex;align-items:center;gap:28px;height:72px}
.nav{display:flex;gap:4px;margin-inline:auto}
.nav a{padding:.55em 1em;border-radius:999px;font-size:.94rem;font-weight:500;color:var(--muted);transition:color .2s,background .2s}
.nav a:hover{color:var(--ink);background:rgba(11,27,51,.05)}
.nav a[aria-current="page"]{color:var(--ink);background:rgba(11,27,51,.07);font-weight:600}
.header-cta{display:flex;align-items:center;gap:8px}
.menu-btn{display:none;width:44px;height:44px;margin-left:auto;align-items:center;justify-content:center;border:1px solid var(--line-strong);border-radius:12px;background:#fff;cursor:pointer}
.menu-btn__close{display:none}
.menu-open .menu-btn__open{display:none}
.menu-open .menu-btn__close{display:block}
.mobile-menu{border-top:1px solid var(--line);background:var(--paper)}
.mobile-menu[hidden]{display:none}
.mobile-menu__inner{display:flex;flex-direction:column;padding-block:12px 24px}
.mobile-menu__inner>a:not(.btn){padding:16px 4px;border-bottom:1px solid var(--line);font:500 1.35rem/1.2 var(--font-serif);color:var(--ink)}
.mobile-menu__inner>a[aria-current="page"]{color:var(--gold-deep)}
.mobile-menu__cta{display:grid;gap:10px;margin-top:22px}
@media (max-width:899px){
  .nav,.header-cta{display:none}
  .menu-btn{display:inline-flex}
  .site-header__inner{height:64px}
}
@media (min-width:900px){.mobile-menu{display:none!important}}

/* ---------- Scroll reveal (only active once the script has confirmed it works) ---------- */
.reveal{transition:opacity .9s var(--ease),transform .9s var(--ease);transition-delay:var(--d,0ms)}
.reveal-ready .reveal:not(.in){opacity:0;transform:translateY(22px)}

/* ==========================================================================
   Home: hero
   ========================================================================== */
.hero{position:relative;isolation:isolate;overflow:clip;padding-block:clamp(40px,6vw,88px) clamp(36px,5vw,64px)}
.hero+.section--tight{padding-top:0}
.hero::before{content:"";position:absolute;z-index:-2;inset:0;background-image:linear-gradient(to right,rgba(11,27,51,.05) 1px,transparent 1px),linear-gradient(to bottom,rgba(11,27,51,.05) 1px,transparent 1px);background-size:64px 64px;-webkit-mask-image:radial-gradient(ellipse 75% 70% at 72% 28%,#000,transparent 75%);mask-image:radial-gradient(ellipse 75% 70% at 72% 28%,#000,transparent 75%)}
.hero::after{content:"";position:absolute;z-index:-1;top:-18%;right:-12%;width:min(78vw,940px);aspect-ratio:1;background:radial-gradient(closest-side,rgba(227,167,47,.34),rgba(227,167,47,.1) 55%,transparent 72%);pointer-events:none}
.hero__grid{display:grid;grid-template-columns:minmax(0,1.04fr) minmax(0,.96fr);gap:clamp(36px,5vw,84px);align-items:center}
.hero__copy>*{animation:rise .9s var(--ease) both}
.hero__copy>:nth-child(2){animation-delay:.08s}
.hero__copy>:nth-child(3){animation-delay:.16s}
.hero__copy>:nth-child(4){animation-delay:.24s}
.hero__copy>:nth-child(5){animation-delay:.32s}
@keyframes rise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
.hero__badge{display:inline-flex;align-items:center;gap:10px;padding:7px 14px 7px 8px;border:1px solid var(--line-strong);border-radius:999px;background:rgba(255,255,255,.7);font:600 .82rem/1.2 var(--font-sans);color:var(--ink)}
.hero__badge b{padding:.3em .7em;border-radius:999px;background:var(--ink);color:#fff;font-size:.66rem;letter-spacing:.1em;text-transform:uppercase}
.hero h1{margin-top:26px;font-size:clamp(2.7rem,1.2rem + 5.3vw,5.35rem);line-height:1.01;letter-spacing:-.03em}
.mark{position:relative;display:inline-block;font-style:italic;color:var(--gold-deep);white-space:nowrap}
.mark svg{position:absolute;left:-2%;bottom:-.14em;width:104%;height:.34em;overflow:visible}
.mark path{fill:none;stroke:var(--gold);stroke-width:5;stroke-linecap:round;stroke-dasharray:420;stroke-dashoffset:420;animation:draw 1.3s .7s var(--ease) forwards}
@keyframes draw{to{stroke-dashoffset:0}}
.hero__lead{max-width:34em;margin-top:28px;font-size:clamp(1.12rem,1.02rem + .45vw,1.34rem);line-height:1.55;color:var(--muted)}
.hero__actions{display:flex;flex-wrap:wrap;align-items:center;gap:14px 22px;margin-top:38px}
.hero__note{max-width:34em;margin-top:22px;font-size:.9rem;line-height:1.55;color:var(--muted)}

.hero__visual{position:relative;width:100%;max-width:560px;margin-inline:auto;aspect-ratio:1/1.14;display:grid;place-items:center;perspective:1400px;--rx:0deg;--ry:0deg}
.rosette{position:absolute;inset:-6% -14%;color:var(--gold-deep);opacity:.4;pointer-events:none;animation:spin 240s linear infinite;will-change:transform}
.rosette svg{width:100%;height:100%}
@keyframes spin{to{transform:rotate(360deg)}}
.sun{position:absolute;top:5%;right:6%;width:clamp(86px,13vw,132px);aspect-ratio:1;border-radius:50%;background:radial-gradient(circle at 32% 28%,#FBE0A0,var(--gold) 62%,#C98A12);box-shadow:0 0 0 14px rgba(227,167,47,.14),0 0 0 34px rgba(227,167,47,.07),0 30px 60px -18px rgba(143,94,0,.6)}

.cert-wrap{position:relative;width:min(100%,432px);transform:rotateX(var(--rx)) rotateY(var(--ry)) rotate(-1.6deg);transition:transform .35s var(--ease);transform-style:preserve-3d;filter:drop-shadow(0 34px 44px rgba(11,27,51,.3))}
.cert{position:relative;overflow:hidden;border:1px solid rgba(11,27,51,.08);border-radius:22px;background:#fff}
.cert__head{position:relative;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 20px;background:var(--ink);color:#fff;overflow:hidden}
.cert__head::before{content:"";position:absolute;inset:0;opacity:.55;background:repeating-radial-gradient(circle at 105% 130%,transparent 0 5px,rgba(227,167,47,.22) 5px 5.7px),repeating-radial-gradient(circle at -6% -40%,transparent 0 6px,rgba(255,255,255,.1) 6px 6.7px)}
.cert__head>*{position:relative}
.cert__brand{display:flex;align-items:center;gap:10px;font:600 .68rem/1.3 var(--font-sans);letter-spacing:.14em;text-transform:uppercase;color:#C9D4E6}
.cert__brand .logo__mark{filter:none}
.cert__body{padding:22px 24px 6px}
.cert__grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 20px}
.k{display:block;font:700 .64rem/1 var(--font-sans);letter-spacing:.13em;text-transform:uppercase;color:var(--muted)}
.v{display:block;margin-top:7px;font:600 .94rem/1.3 var(--font-sans);color:var(--ink)}
.cert__amount{display:flex;align-items:baseline;gap:10px;margin-top:24px}
.cert__amount .cur{font:700 .8rem/1 var(--font-sans);letter-spacing:.1em;color:var(--muted)}
.cert__amount b{font:400 clamp(2.3rem,5.2vw,3rem)/1 var(--font-serif);letter-spacing:-.025em;font-variant-numeric:lining-nums tabular-nums;color:var(--ink)}
.cert__vat{margin-top:8px;font-size:.84rem;color:var(--muted)}
.cert__rule{position:relative;height:0;margin:20px -24px 0;border-top:2px dashed var(--line)}
.cert__rule::before,.cert__rule::after{content:"";position:absolute;top:-12px;width:22px;height:22px;border-radius:50%;background:var(--paper)}
.cert__rule::before{left:-11px}
.cert__rule::after{right:-11px}
.cert__foot{display:flex;align-items:center;gap:18px;padding:18px 24px 22px}
.cert__qr{flex:none;width:106px;height:106px;padding:11px;border:1px solid var(--line);border-radius:14px;background:#fff}
.cert__qr svg{width:100%;height:100%}
.cert__fp{min-width:0}
.cert__fp .mono{display:block;margin-top:7px;font-size:.78rem;line-height:1.45;color:var(--ink);word-break:break-all}
.cert__fp p{margin-top:9px;font-size:.78rem;line-height:1.4;color:var(--muted)}
.cert__caption{padding:9px 24px;border-top:1px solid var(--line);background:var(--paper);font:600 .66rem/1 var(--font-sans);letter-spacing:.11em;text-transform:uppercase;color:var(--muted);text-align:center}

.float{position:absolute;z-index:3;display:inline-flex;align-items:center;gap:10px;padding:9px 15px 9px 9px;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:var(--shadow);font:600 .8rem/1.25 var(--font-sans);color:var(--ink);white-space:nowrap;animation:bob 7.5s ease-in-out infinite}
.float__ico{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:var(--green-soft);color:var(--green)}
.float__ico--gold{background:var(--gold-soft);color:var(--gold-deep)}
.float__ico--blue{background:var(--blue-soft);color:var(--blue)}
.float small{display:block;font-weight:500;color:var(--muted);font-size:.72rem}
/* Chips sit in the gaps above and below the card, plus one on the right edge
   beside the amount, where the card is empty; none may cover the QR or text. */
.float--1{top:0;left:2%}
.float--2{top:41%;right:-4%;animation-delay:-2.2s}
.float--3{bottom:0;left:0;animation-delay:-4.3s}
.float--4{bottom:1%;right:2%;animation-delay:-1.1s}
@keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}

@media (max-width:1020px){
  .hero__grid{grid-template-columns:1fr}
  .hero__visual{max-width:500px;aspect-ratio:auto;padding-block:88px;margin-top:8px}
  .float--2{right:0}
}
@media (max-width:560px){
  .hero__visual{padding-block:84px 76px}
  .float{padding:7px 12px 7px 7px;font-size:.74rem}
  .float--2,.float--4{display:none}
  .float--3{left:0}
  .cert__grid{gap:14px}
  .cert__foot{gap:14px}
  .cert__qr{width:92px;height:92px;padding:9px}
}

/* ---------- Trust band under the hero ---------- */
.pillars{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid var(--line);border-radius:var(--radius-lg);background:#fff;box-shadow:var(--shadow-sm);overflow:hidden}
.pillar{display:flex;gap:18px;padding:clamp(22px,3vw,34px)}
.pillar+.pillar{border-left:1px solid var(--line)}
.pillar__ico{flex:none;display:grid;place-items:center;width:46px;height:46px;border-radius:13px;background:var(--gold-soft);color:var(--gold-deep)}
.pillar h3{font:600 1.05rem/1.3 var(--font-sans);letter-spacing:-.01em}
.pillar p{margin-top:6px;font-size:.94rem;line-height:1.55;color:var(--muted)}
@media (max-width:900px){
  .pillars{grid-template-columns:1fr}
  .pillar+.pillar{border-left:0;border-top:1px solid var(--line)}
}

/* ==========================================================================
   How it works
   ========================================================================== */
.steps{position:relative;display:grid;grid-template-columns:repeat(4,1fr);gap:22px}
.steps::before{content:"";position:absolute;top:21px;left:4%;right:4%;border-top:1.5px dashed var(--line-strong)}
.step{position:relative;display:flex;flex-direction:column}
.step__num{position:relative;display:grid;place-items:center;width:42px;height:42px;border:1.5px solid var(--line-strong);border-radius:50%;background:var(--paper);font:500 1.05rem/1 var(--font-serif);color:var(--gold-deep)}
.step h3{margin-top:20px;font:600 1.12rem/1.3 var(--font-sans);letter-spacing:-.012em}
.step p{margin-top:8px;font-size:.96rem;line-height:1.6;color:var(--muted)}
.step__mock{margin-top:22px;flex:1;display:flex;flex-direction:column;justify-content:center;gap:8px;min-height:148px;padding:16px;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:var(--shadow-sm);font-size:.78rem}
/* Subgrid lines the four cards' numbers, titles, copy and mocks up across
   columns even when the copy runs to different lengths. */
@supports (grid-template-rows:subgrid){
  .step{display:grid;grid-row:span 4;grid-template-rows:subgrid;row-gap:0}
}
.code{font:500 .74rem/1.65 var(--font-mono);color:#33415C;white-space:pre;overflow:hidden}
.code .c-k{color:#8F5E00}.code .c-s{color:#0E7A5A}.code .c-m{color:#8793A8}.code .c-n{color:#2159B8}
.tick{display:flex;align-items:center;gap:9px;font-weight:500;color:var(--text)}
.tick i{flex:none;display:grid;place-items:center;width:18px;height:18px;border-radius:50%;background:var(--green-soft);color:var(--green)}
.tick i svg{width:11px;height:11px;stroke-width:3}
.tick--end{margin-top:2px;padding-top:9px;border-top:1px dashed var(--line);font-weight:700;color:var(--green)}
.pair{display:grid;grid-template-columns:1fr 22px 1fr;align-items:center;gap:6px}
.pill{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 10px;border:1px solid var(--line);border-radius:9px;background:var(--paper);font-weight:600;color:var(--ink);white-space:nowrap}
.pill small{font-size:.66rem;font-weight:600;color:var(--muted)}
.pill--bad{border-color:#EDBDB5;background:var(--red-soft);color:#8E2A1D}
.link-line{height:1.5px;background:var(--line-strong)}
.link-line--bad{background:var(--red);opacity:.6}
.chainmini{display:flex;align-items:center;gap:6px}
.chainmini .blk{flex:1;padding:8px 6px;border:1px solid var(--line);border-radius:8px;background:var(--paper);font:600 .62rem/1.4 var(--font-mono);color:var(--muted);text-align:center}
.chainmini .blk b{display:block;color:var(--ink)}
.chainmini .lnk{flex:none;width:10px;height:1.5px;background:var(--gold)}
@media (max-width:1020px){
  .steps{grid-template-columns:repeat(2,1fr);gap:34px 22px}
  .steps::before{display:none}
}
@media (max-width:620px){.steps{grid-template-columns:1fr}}

/* ==========================================================================
   Platform bento
   ========================================================================== */
.bento{display:grid;grid-template-columns:repeat(6,1fr);gap:18px}
.tile{--mx:50%;--my:0%;position:relative;display:flex;flex-direction:column;grid-column:span 2;padding:clamp(22px,2.4vw,30px);border:1px solid var(--line);border-radius:var(--radius-lg);background:#fff;box-shadow:var(--shadow-sm);overflow:hidden;transition:transform .35s var(--ease),box-shadow .35s var(--ease),border-color .3s}
.tile::before{content:"";position:absolute;inset:0;pointer-events:none;opacity:0;background:radial-gradient(420px circle at var(--mx) var(--my),rgba(227,167,47,.17),transparent 62%);transition:opacity .3s}
.tile:hover{transform:translateY(-3px);border-color:var(--line-strong);box-shadow:var(--shadow)}
.tile:hover::before{opacity:1}
.tile>*{position:relative}
.tile--w3{grid-column:span 3}
.tile--tall{grid-row:span 2}
.tile__ico{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:var(--ink);color:var(--gold-2)}
.tile h3{margin-top:20px;font:600 1.18rem/1.28 var(--font-sans);letter-spacing:-.015em}
.tile p{margin-top:9px;font-size:.96rem;line-height:1.6;color:var(--muted)}
.tile__vis{margin-top:auto;padding-top:24px}
.tile .link-arrow{margin-top:18px;align-self:flex-start;font-size:.92rem}
.mini{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--paper);font-size:.78rem}
.mini+.mini{margin-top:10px}
.mini__row{display:flex;align-items:center;justify-content:space-between;gap:10px}
.mini__row+.mini__row{margin-top:9px;padding-top:9px;border-top:1px solid var(--line)}
.mini .t{font-weight:600;color:var(--ink)}
.mini .s{color:var(--muted)}
.bars{display:flex;align-items:flex-end;gap:7px;height:78px}
.bars i{flex:1;border-radius:5px 5px 2px 2px;background:linear-gradient(var(--gold-2),var(--gold))}
.bars i:nth-child(odd){background:linear-gradient(#27456F,var(--ink))}
.meter{height:8px;border-radius:99px;background:var(--paper-3);overflow:hidden}
.meter i{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--gold),var(--gold-2))}
@media (max-width:1020px){
  .bento{grid-template-columns:repeat(2,1fr)}
  .tile,.tile--w3{grid-column:span 1}
  .tile--tall{grid-row:auto}
}
@media (max-width:640px){.bento{grid-template-columns:1fr}}

/* ==========================================================================
   Audiences (tabs) and mock windows
   ========================================================================== */
.tablist{display:inline-flex;flex-wrap:wrap;gap:6px;padding:6px;border:1px solid var(--line);border-radius:999px;background:#fff;box-shadow:var(--shadow-sm)}
.tab{padding:.7em 1.3em;border:0;border-radius:999px;background:transparent;font:600 .93rem/1 var(--font-sans);color:var(--muted);cursor:pointer;transition:background .2s,color .2s}
.tab:hover{color:var(--ink)}
.tab[aria-selected="true"]{background:var(--ink);color:#fff}
[data-tabs]:not(.is-tabbed) .tablist{display:none}
.tabpanel{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:clamp(28px,5vw,72px);align-items:center;margin-top:clamp(28px,4vw,48px)}
.tabpanel[hidden]{display:none}
[data-tabs]:not(.is-tabbed) .tabpanel+.tabpanel{margin-top:clamp(56px,8vw,96px)}
.tabpanel h3{font-size:clamp(1.55rem,1.2rem + 1.3vw,2.3rem);line-height:1.12}
.tabpanel>div>p{margin-top:14px;color:var(--muted)}
.checklist{margin-top:24px;display:grid;gap:13px}
.checklist li{display:flex;gap:12px;align-items:flex-start;line-height:1.5}
.checklist li i{flex:none;display:grid;place-items:center;width:22px;height:22px;margin-top:1px;border-radius:50%;background:var(--ink);color:var(--gold-2)}
.checklist li i svg{width:12px;height:12px;stroke-width:2.8}
.checklist--light li i{background:rgba(255,255,255,.12)}
.tabpanel .link-arrow{margin-top:28px}

.window{border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:var(--shadow);overflow:hidden}
.window__bar{display:flex;align-items:center;gap:7px;padding:11px 14px;border-bottom:1px solid var(--line);background:linear-gradient(#FCFAF5,#F5F0E4)}
.window__bar i{width:9px;height:9px;border-radius:50%;background:#DBD1B9}
.window__bar span{margin-left:10px;font:600 .72rem/1 var(--font-sans);letter-spacing:.02em;color:var(--muted)}
.window__bar em{margin-left:auto;font:600 .62rem/1 var(--font-sans);font-style:normal;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.window__body{padding:18px}
.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.kpi{padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--paper)}
.kpi small{display:block;font:700 .6rem/1.2 var(--font-sans);letter-spacing:.11em;text-transform:uppercase;color:var(--muted)}
.kpi b{display:block;margin-top:8px;font:500 1.4rem/1 var(--font-serif);letter-spacing:-.02em;color:var(--ink);font-variant-numeric:lining-nums tabular-nums}
.rows{margin-top:12px;border:1px solid var(--line);border-radius:12px;overflow:hidden}
.rows .r{display:grid;grid-template-columns:1.25fr 1fr auto;align-items:center;gap:10px;padding:10px 12px;font-size:.8rem}
.rows .r+.r{border-top:1px solid var(--line)}
.rows .r b{font-weight:600;color:var(--ink)}
.rows .r span{color:var(--muted)}
.rows .r--head{background:var(--paper);font:700 .6rem/1 var(--font-sans);letter-spacing:.11em;text-transform:uppercase;color:var(--muted)}
.codeblock{padding:20px 22px;border-radius:0 0 18px 18px;background:var(--ink);font:500 .78rem/1.75 var(--font-mono);color:#CFDAEC;white-space:pre;overflow-x:auto}
.codeblock .c-k{color:var(--gold-2)}.codeblock .c-s{color:#7FD6B3}.codeblock .c-m{color:#7F8FAB}.codeblock .c-n{color:#8DB4F5}
.window--dark{border-color:var(--ink-3);background:var(--ink)}
.window--dark .window__bar{background:var(--ink-2);border-color:var(--ink-line)}
.window--dark .window__bar i{background:#34507F}
.window--dark .window__bar span,.window--dark .window__bar em{color:var(--on-ink-muted)}
.tab__short{display:none}
@media (max-width:900px){.tabpanel{grid-template-columns:1fr}}
@media (max-width:560px){
  .tablist{display:grid;grid-template-columns:repeat(3,1fr);width:100%;gap:4px;padding:5px}
  .tab{padding:.8em .3em;font-size:.84rem;text-align:center}
  .tab__long{display:none}
  .tab__short{display:inline}
}
@media (max-width:480px){
  .kpis{grid-template-columns:1fr 1fr}.kpi:last-child{grid-column:1/-1}
  .rows .r{grid-template-columns:1.1fr 1fr auto;gap:8px;padding:10px;font-size:.74rem}
  .rows .chip{padding:.3em .6em;font-size:.58rem}
}

/* ==========================================================================
   Verification spotlight and the lookup form
   ========================================================================== */
.spotlight{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:clamp(32px,6vw,96px);align-items:center}
.lookup{margin-top:30px}
.lookup__label{display:block;margin-bottom:10px;font:600 .88rem/1.2 var(--font-sans);color:var(--ink)}
.lookup__row{display:flex;gap:10px;padding:7px;border:1px solid var(--line-strong);border-radius:999px;background:#fff;box-shadow:var(--shadow-sm);transition:border-color .2s,box-shadow .2s}
.lookup__row:focus-within{border-color:var(--ink);box-shadow:0 0 0 4px rgba(11,27,51,.1)}
.lookup__input{flex:1;min-width:0;padding:0 16px;border:0;background:transparent;font:500 1rem/1 var(--font-mono);color:var(--ink);outline:0}
.lookup__input::placeholder{color:#97A0B1;font-family:var(--font-sans)}
.lookup__error{margin-top:12px;padding:12px 16px;border:1px solid #EDBDB5;border-radius:12px;background:var(--red-soft);font-size:.92rem;color:#8E2A1D}
.lookup__hint{margin-top:14px;font-size:.88rem;color:var(--muted)}
.section--ink .lookup__hint{color:var(--on-ink-muted)}
.verifycard{position:relative;max-width:470px;margin-inline:auto;border:1px solid var(--line);border-radius:24px;background:#fff;box-shadow:var(--shadow-lg);overflow:hidden}
.verifycard__top{padding:30px 28px 24px;text-align:center;background:linear-gradient(var(--green-soft),#F1FAF5)}
.seal{position:relative;display:grid;place-items:center;width:78px;height:78px;margin:0 auto 18px;border-radius:50%;background:var(--green);color:#fff;box-shadow:0 0 0 8px rgba(14,122,90,.14),0 0 0 18px rgba(14,122,90,.07)}
.seal svg{width:38px;height:38px;stroke-width:2.4}
.verifycard__top small{display:block;font:700 .64rem/1 var(--font-sans);letter-spacing:.15em;text-transform:uppercase;color:#0A5A42}
.verifycard__top h3{margin-top:10px;font:400 1.7rem/1.15 var(--font-serif);color:var(--ink)}
.verifycard dl{margin:0;padding:8px 28px 6px}
.verifycard dl div{display:grid;grid-template-columns:118px 1fr;gap:12px;padding:13px 0;font-size:.9rem}
.verifycard dl div+div{border-top:1px solid var(--line)}
.verifycard dt{color:var(--muted)}
.verifycard dd{margin:0;font-weight:600;color:var(--ink);word-break:break-word}
.verifycard__note{padding:14px 28px;border-top:1px solid var(--line);background:var(--paper);font-size:.78rem;line-height:1.5;color:var(--muted)}
@media (max-width:900px){.spotlight{grid-template-columns:1fr}}
@media (max-width:520px){
  .lookup__row{flex-direction:column;border-radius:20px;padding:10px}
  .lookup__input{padding:14px 12px}
  .verifycard dl div{grid-template-columns:96px 1fr}
}

/* ==========================================================================
   Security / trust
   ========================================================================== */
.controls{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;border:1px solid var(--ink-line);border-radius:var(--radius-lg);background:var(--ink-line);overflow:hidden}
.control{padding:clamp(24px,2.6vw,34px);background:var(--ink-2);transition:background .3s}
.control:hover{background:#15305A}
.control__ico{display:grid;place-items:center;width:44px;height:44px;border:1px solid rgba(227,167,47,.4);border-radius:13px;background:linear-gradient(135deg,rgba(227,167,47,.28),rgba(227,167,47,.08));color:var(--gold-2)}
.control h3{margin-top:20px;font:600 1.1rem/1.3 var(--font-sans);letter-spacing:-.012em;color:#fff}
.control p{margin-top:9px;font-size:.94rem;line-height:1.6;color:var(--on-ink-muted)}
.chain{display:flex;align-items:stretch;gap:0;margin-top:clamp(36px,5vw,64px);padding:clamp(18px,2.4vw,26px);border:1px solid var(--ink-line);border-radius:var(--radius-lg);background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.01));overflow-x:auto}
.block{flex:1 0 190px;padding:16px;border:1px solid var(--ink-line);border-radius:14px;background:var(--ink);font:500 .72rem/1.7 var(--font-mono);color:var(--on-ink-muted)}
.block b{display:block;margin-bottom:6px;font:700 .62rem/1 var(--font-sans);letter-spacing:.13em;text-transform:uppercase;color:var(--gold-2)}
.block span{display:block;color:#fff}
.chain__link{flex:0 0 38px;align-self:center;position:relative;height:2px;background:linear-gradient(90deg,var(--gold),rgba(227,167,47,.3));overflow:hidden}
.chain__link::after{content:"";position:absolute;inset:0;width:14px;background:#fff;opacity:.8;animation:pulse 2.4s linear infinite}
@keyframes pulse{from{transform:translateX(-14px)}to{transform:translateX(38px)}}
.chain--stack{flex-direction:column;align-items:stretch;margin-top:0;overflow:visible}
.chain--stack .block{flex:none}
.chain--stack .chain__link{flex:0 0 26px;width:2px;height:26px;background:linear-gradient(var(--gold),rgba(227,167,47,.3))}
.chain--stack .chain__link::after{inset:0 auto auto 0;width:100%;height:12px;animation-name:pulse-v}
@keyframes pulse-v{from{transform:translateY(-12px)}to{transform:translateY(26px)}}
.chain__status{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:16px;color:var(--on-ink-muted);font-size:.88rem}
@media (max-width:980px){.controls{grid-template-columns:repeat(2,1fr)}}
@media (max-width:620px){.controls{grid-template-columns:1fr}}

/* ==========================================================================
   Access / plans
   ========================================================================== */
.access{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr);gap:clamp(28px,5vw,80px);align-items:start}
.process{counter-reset:p;margin-top:30px;display:grid;gap:0}
.process li{counter-increment:p;position:relative;padding:0 0 26px 58px}
.process li::before{content:counter(p);position:absolute;left:0;top:0;display:grid;place-items:center;width:38px;height:38px;border:1.5px solid var(--line-strong);border-radius:50%;background:var(--paper);font:500 1rem/1 var(--font-serif);color:var(--gold-deep)}
.process li::after{content:"";position:absolute;left:18.5px;top:44px;bottom:6px;border-left:1.5px dashed var(--line-strong)}
.process li:last-child{padding-bottom:0}
.process li:last-child::after{display:none}
.process b{display:block;font:600 1.02rem/1.3 var(--font-sans);color:var(--ink)}
.process span{display:block;margin-top:4px;font-size:.94rem;line-height:1.55;color:var(--muted)}
.plan{position:relative;padding:clamp(26px,3vw,40px);border:1.5px solid transparent;border-radius:var(--radius-lg);background:linear-gradient(#fff,#fff) padding-box,linear-gradient(135deg,var(--gold),#EADBB4 38%,#EADBB4 62%,var(--gold-2)) border-box;box-shadow:var(--shadow)}
.plan__head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.plan h3{font-size:clamp(1.7rem,1.3rem + 1.2vw,2.3rem);line-height:1.1}
.plan__sub{margin-top:8px;font-size:.95rem;color:var(--muted)}
.allowances{display:grid;grid-template-columns:repeat(auto-fit,minmax(96px,1fr));gap:10px;margin-top:26px}
.allowance{padding:13px 12px;border:1px solid var(--line);border-radius:14px;background:var(--paper)}
.allowance b{display:block;font:500 1.35rem/1 var(--font-serif);letter-spacing:-.02em;color:var(--ink);font-variant-numeric:lining-nums tabular-nums}
.allowance small{display:block;margin-top:7px;font-size:.78rem;line-height:1.35;color:var(--muted)}
.features{display:grid;grid-template-columns:1fr 1fr;gap:14px 28px;margin-top:28px;padding-top:26px;border-top:1px dashed var(--line-strong)}
.features li{display:flex;gap:11px;align-items:flex-start;font-size:.94rem;line-height:1.45}
.features li i{flex:none;display:grid;place-items:center;width:20px;height:20px;margin-top:1px;border-radius:50%;background:var(--green-soft);color:var(--green)}
.features li i svg{width:11px;height:11px;stroke-width:3}
.features b{display:block;font-weight:600;color:var(--ink)}
.features span{display:block;margin-top:2px;font-size:.84rem;color:var(--muted)}
.plan__cta{display:flex;flex-wrap:wrap;gap:12px;margin-top:30px}
.plan__fine{margin-top:18px;font-size:.84rem;line-height:1.5;color:var(--muted)}
.empty{padding:26px;border:1px dashed var(--line-strong);border-radius:var(--radius);background:#fff;color:var(--muted)}
.access>:first-child{position:sticky;top:112px}
@media (max-width:960px){.access{grid-template-columns:1fr}.access>:first-child{position:static}}
@media (max-width:560px){.features{grid-template-columns:1fr}}

/* ==========================================================================
   FAQ
   ========================================================================== */
.faq{max-width:860px;margin-inline:auto;border-top:1px solid var(--line-strong)}
.faq details{border-bottom:1px solid var(--line-strong)}
.faq summary{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:24px 4px;font:400 clamp(1.2rem,1.05rem + .6vw,1.5rem)/1.3 var(--font-serif);letter-spacing:-.015em;color:var(--ink);cursor:pointer;list-style:none}
.faq summary::-webkit-details-marker{display:none}
.faq summary:hover{color:var(--gold-deep)}
.faq summary .plus{flex:none;display:grid;place-items:center;width:34px;height:34px;border:1px solid var(--line-strong);border-radius:50%;background:#fff;transition:transform .35s var(--ease),background .2s,color .2s}
.faq details[open] summary .plus{transform:rotate(45deg);background:var(--ink);color:#fff;border-color:var(--ink)}
.faq .answer{padding:0 4px 28px;max-width:70ch;color:var(--muted);line-height:1.7}
.faq .answer p+p{margin-top:12px}
.faq .answer a{color:var(--ink);border-bottom:1.5px solid var(--gold)}

/* ==========================================================================
   Closing call to action
   ========================================================================== */
.cta{position:relative;isolation:isolate;overflow:hidden;margin-block:0;padding-block:clamp(72px,9vw,128px) clamp(250px,30vw,420px);background:radial-gradient(ellipse 80% 60% at 50% 0,#14305A,transparent 70%),var(--ink);color:#fff;text-align:center}
.sunset{position:absolute;z-index:-1;left:50%;bottom:clamp(20px,3vw,44px);width:clamp(160px,22vw,280px);aspect-ratio:1;transform:translateX(-50%);border-radius:50%;background:radial-gradient(circle at 50% 38%,#FCE3A6,var(--gold) 58%,#C98A12);box-shadow:0 0 0 22px rgba(227,167,47,.1),0 0 0 54px rgba(227,167,47,.05),0 0 140px 30px rgba(227,167,47,.28)}
.cta h2{margin-inline:auto;max-width:15em;font-size:clamp(2.2rem,1.3rem + 3.6vw,4.3rem);line-height:1.04;color:#fff}
.cta .lead{max-width:36em;margin:22px auto 0;color:var(--on-ink-muted)}
.cta__actions{display:flex;flex-wrap:wrap;justify-content:center;gap:14px;margin-top:38px}
.dunes{position:absolute;z-index:-1;left:0;right:0;bottom:-1px;width:100%;height:clamp(110px,18vw,230px)}
.dunes path:nth-child(1){fill:#0F2547}
.dunes path:nth-child(2){fill:#0C1E3B}
.dunes path:nth-child(3){fill:#0A1830}

/* ==========================================================================
   Footer
   ========================================================================== */
.site-footer{padding-block:clamp(52px,6vw,84px) 34px;background:var(--ink-deep);color:var(--on-ink-muted);font-size:.94rem}
.site-footer a{transition:color .2s}
.site-footer a:hover{color:#fff}
.site-footer__grid{display:grid;grid-template-columns:1.5fr repeat(3,1fr);gap:clamp(28px,4vw,56px)}
.site-footer__about p{max-width:30em;margin-top:18px;line-height:1.6}
.site-footer h2{margin-bottom:16px;font:700 .72rem/1 var(--font-sans);letter-spacing:.15em;text-transform:uppercase;color:#fff}
.site-footer li+li{margin-top:11px}
.status{display:inline-flex;align-items:center;gap:9px;margin-top:20px!important;padding:7px 13px;border:1px solid var(--ink-line);border-radius:999px;background:rgba(255,255,255,.04);font-size:.82rem;color:#fff}
.status[hidden]{display:none}
.status__dot{width:8px;height:8px;border-radius:50%;background:#35D39B;box-shadow:0 0 0 0 rgba(53,211,155,.6);animation:ping 2.2s infinite}
@keyframes ping{0%{box-shadow:0 0 0 0 rgba(53,211,155,.55)}70%{box-shadow:0 0 0 9px rgba(53,211,155,0)}100%{box-shadow:0 0 0 0 rgba(53,211,155,0)}}
.site-footer__legal{display:grid;gap:8px;margin-top:clamp(36px,5vw,60px);padding-top:26px;border-top:1px solid var(--ink-line);font-size:.82rem;line-height:1.6}
@media (max-width:900px){.site-footer__grid{grid-template-columns:1fr 1fr}.site-footer__about{grid-column:1/-1}}
@media (max-width:480px){.site-footer__grid{grid-template-columns:1fr}}

/* ==========================================================================
   Inner pages (product, trust, plans, verify)
   ========================================================================== */
.pagehero{position:relative;isolation:isolate;overflow:clip;padding-block:clamp(48px,7vw,104px) clamp(40px,5vw,72px)}
.pagehero::before{content:"";position:absolute;z-index:-1;top:-40%;right:-8%;width:min(70vw,780px);aspect-ratio:1;background:radial-gradient(closest-side,rgba(227,167,47,.26),transparent 70%)}
.pagehero h1{margin-top:22px;max-width:15em;font-size:clamp(2.4rem,1.3rem + 4.4vw,4.6rem);line-height:1.03;letter-spacing:-.028em}
.pagehero .lead{max-width:40em;margin-top:24px}
.pagehero--center{text-align:center}
.pagehero--center h1,.pagehero--center .lead{margin-inline:auto}
.pagehero--center .eyebrow{justify-content:center}

.subnav{position:sticky;top:72px;z-index:40;border-block:1px solid var(--line);background:rgba(250,246,238,.9)}
@supports (backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px)){.subnav{-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px)}}
.subnav__inner{display:flex;gap:6px;overflow-x:auto;padding-block:10px;scrollbar-width:none}
.subnav__inner::-webkit-scrollbar{display:none}
.subnav a{flex:none;padding:.5em 1em;border-radius:999px;font-size:.88rem;font-weight:600;color:var(--muted);transition:background .2s,color .2s}
.subnav a:hover{background:rgba(11,27,51,.06);color:var(--ink)}
@media (max-width:899px){.subnav{top:64px}}

.module{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:clamp(32px,6vw,96px);align-items:center;padding-block:clamp(52px,7vw,104px);border-top:1px solid var(--line)}
.module:first-of-type{border-top:0}
.module:nth-of-type(even) .module__vis{order:-1}
.module__num{display:inline-block;margin-bottom:14px;font:italic 400 1.5rem/1 var(--font-serif);color:var(--gold-deep)}
.module h2{font-size:clamp(1.9rem,1.3rem + 2.2vw,3rem);line-height:1.08}
.module__copy>p{margin-top:16px;color:var(--muted)}
.module .checklist{margin-top:26px}
.module__vis{min-width:0}
@media (max-width:900px){
  .module{grid-template-columns:1fr}
  .module:nth-of-type(even) .module__vis{order:0}
}

.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.card{padding:clamp(24px,2.6vw,32px);border:1px solid var(--line);border-radius:var(--radius-lg);background:#fff;box-shadow:var(--shadow-sm)}
.card__ico{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:var(--gold-soft);color:var(--gold-deep)}
.card h3{margin-top:20px;font:600 1.14rem/1.3 var(--font-sans);letter-spacing:-.014em}
.card p{margin-top:9px;font-size:.96rem;line-height:1.6;color:var(--muted)}
.card ul{margin-top:14px;display:grid;gap:9px;font-size:.92rem;color:var(--muted)}
.card li{display:flex;gap:10px;line-height:1.45}
.card li::before{content:"";flex:none;width:6px;height:6px;margin-top:.55em;border-radius:50%;background:var(--gold)}
.cards--2{grid-template-columns:repeat(2,1fr)}
@media (max-width:980px){.cards{grid-template-columns:repeat(2,1fr)}}
@media (max-width:640px){.cards,.cards--2{grid-template-columns:1fr}}

.callout{display:flex;gap:18px;padding:clamp(22px,2.6vw,32px);border:1px solid #EBCF8B;border-radius:var(--radius-lg);background:linear-gradient(135deg,#FCF3D9,#FBF7EA)}
.callout__ico{flex:none;display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:var(--gold);color:var(--ink)}
.callout h3{font:600 1.08rem/1.3 var(--font-sans);letter-spacing:-.012em}
.callout p{margin-top:8px;font-size:.96rem;line-height:1.65;color:#4B4429}
.callout p+p{margin-top:10px}

.lookup-card{max-width:680px;margin-inline:auto;padding:clamp(24px,3vw,40px);border:1px solid var(--line);border-radius:var(--radius-lg);background:#fff;box-shadow:var(--shadow)}
.lookup-card .lookup{margin-top:0}
.sees{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.sees .card h3{display:flex;align-items:center;gap:10px}
.sees .card h3 i{display:grid;place-items:center;width:26px;height:26px;border-radius:50%;font-style:normal}
.sees .yes h3 i{background:var(--green-soft);color:var(--green)}
.sees .no h3 i{background:var(--red-soft);color:var(--red)}
.sees .yes li::before{background:var(--green)}
.sees .no li::before{background:var(--red);opacity:.7}
@media (max-width:760px){.sees{grid-template-columns:1fr}}

/* Stage flow (chips joined by small arrows) and invoice totals, used in the mocks */
.flow{display:flex;flex-wrap:wrap;align-items:center;gap:8px 6px;margin-top:16px}
.flow i{position:relative;flex:none;width:14px;height:1.5px;background:var(--line-strong)}
.flow i::after{content:"";position:absolute;right:-1px;top:-3px;border-left:5px solid var(--line-strong);border-top:3.5px solid transparent;border-bottom:3.5px solid transparent}
.totals{display:grid;gap:7px;margin-top:14px;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--paper);font-size:.84rem}
.totals div{display:flex;align-items:baseline;justify-content:space-between;color:var(--muted)}
.totals b{font-weight:600;color:var(--ink);font-variant-numeric:lining-nums tabular-nums}
.totals__sum{padding-top:8px;border-top:1px dashed var(--line-strong)}
.totals__sum b{font:500 1.1rem/1 var(--font-serif)}

/* ---------- Motion preferences ---------- */
@media (prefers-reduced-motion:reduce){
  html{scroll-behavior:auto}
  *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important}
  .rosette{animation:none}
  .mark path{stroke-dashoffset:0}
}
@endverbatim
</style>
