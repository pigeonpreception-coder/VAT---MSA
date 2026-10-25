@extends('site.layout')

@section('title', 'VAT-MSA — VAT compliance, certified at the source')
@section('description', 'VAT-MSA validates and certifies every invoice, reconciles supplier and customer records, and keeps a tamper-evident audit trail. An e-VAT management platform for Namibia, now in pilot.')

@section('content')

{{-- ======================= Hero ======================= --}}
<section class="hero" aria-labelledby="hero-title">
    <div class="wrap hero__grid">
        <div class="hero__copy">
            <p class="hero__badge"><b>Pilot</b> Now accepting access requests</p>

            <h1 id="hero-title">VAT compliance, <span class="mark">certified<svg viewBox="0 0 220 14" preserveAspectRatio="none" aria-hidden="true"><path d="M3 9C60 3 140 2 217 7"/></svg></span> at the source.</h1>

            <p class="hero__lead">VAT-MSA validates every invoice, issues a verifiable certificate, reconciles supplier and customer records, and keeps a tamper-evident audit trail. Businesses file with confidence. Tax authorities oversee with clarity.</p>

            <div class="hero__actions">
                <a class="btn btn--lg" href="{{ route('signup.create') }}">Request access <x-site.icon name="arrow-right" :size="18" /></a>
                <a class="btn btn--ghost btn--lg" href="{{ route('login') }}">Sign in</a>
                <a class="link-arrow" href="{{ route('site.verify') }}">Verify an invoice <x-site.icon name="arrow-up-right" :size="16" /></a>
            </div>

            <p class="hero__note">Access is granted by application, after administrator verification. No payment is taken online.</p>
        </div>

        <div class="hero__visual" data-tilt aria-hidden="true">
            <div class="rosette">@include('site.partials.rosette')</div>
            <div class="sun"></div>

            <div class="cert-wrap">
                <figure class="cert" style="margin:0">
                    <div class="cert__head">
                        <div class="cert__brand"><x-site.logo :wordmark="false" :size="26" /> Tax invoice certificate</div>
                        <span class="chip chip--ok"><span class="dot"></span> Certified</span>
                    </div>
                    <div class="cert__body">
                        <div class="cert__grid">
                            <div><span class="k">Supplier</span><span class="v">Sample Trading (Pty) Ltd</span></div>
                            <div><span class="k">Invoice</span><span class="v mono">IN********18</span></div>
                            <div><span class="k">Certified</span><span class="v">24 Oct 2026, 09:41</span></div>
                            <div><span class="k">VAT category</span><span class="v">Standard &middot; 15%</span></div>
                        </div>
                        <div class="cert__amount"><span class="cur">NAD</span><b>11,500.00</b></div>
                        <p class="cert__vat">Includes VAT of NAD 1,500.00</p>
                        <div class="cert__rule"></div>
                    </div>
                    <div class="cert__foot">
                        <div class="cert__qr">@include('site.partials.qr')</div>
                        <div class="cert__fp">
                            <span class="k">Invoice fingerprint</span>
                            <span class="mono">9f2c41ab07e3d5…b71e0d58</span>
                            <p>Scan the code or open the link to verify this certificate.</p>
                        </div>
                    </div>
                    <figcaption class="cert__caption">Illustrative sample</figcaption>
                </figure>
            </div>

            <div class="float float--1"><span class="float__ico"><x-site.icon name="check-circle" :size="17" /></span><span>VAT rules applied<small>Standard rate &middot; 15%</small></span></div>
            <div class="float float--2"><span class="float__ico float__ico--blue"><x-site.icon name="scale" :size="17" /></span><span>Reconciled<small>Matches customer record</small></span></div>
            <div class="float float--3"><span class="float__ico float__ico--gold"><x-site.icon name="lock" :size="17" /></span><span>Step-up verified<small>Authenticator code</small></span></div>
            <div class="float float--4"><span class="float__ico"><x-site.icon name="link" :size="17" /></span><span>Audit chain intact<small>Entry appended</small></span></div>
        </div>
    </div>
</section>

{{-- ======================= Trust band ======================= --}}
<section class="section--tight" aria-labelledby="pillars-title">
    <div class="wrap">
        <h2 class="sr-only" id="pillars-title">What sets VAT-MSA apart</h2>
        <div class="pillars reveal">
            <div class="pillar">
                <span class="pillar__ico"><x-site.icon name="file-check" /></span>
                <div>
                    <h3>Certified, not just recorded</h3>
                    <p>Every invoice is checked against VAT rules and fingerprinted, then issued a certificate anyone can verify from its link or QR code.</p>
                </div>
            </div>
            <div class="pillar">
                <span class="pillar__ico"><x-site.icon name="link" /></span>
                <div>
                    <h3>Tamper-evident by design</h3>
                    <p>Each significant action is appended to a hash-chained audit trail whose integrity can be verified on demand.</p>
                </div>
            </div>
            <div class="pillar">
                <span class="pillar__ico"><x-site.icon name="lock" /></span>
                <div>
                    <h3>Controlled, not open</h3>
                    <p>Sensitive actions need a fresh authenticator-app code, and access follows roles, scopes and approvals.</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ======================= How it works ======================= --}}
<section class="section section--sand" id="how-it-works" aria-labelledby="how-title">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">How it works</span>
            <h2 class="h2" id="how-title">From invoice to audit trail, <em class="accent">in four steps.</em></h2>
            <p class="lead">One controlled path from the moment an invoice is issued to the moment it is filed, reconciled and, if ever needed, audited.</p>
        </div>

        <ol class="steps">
            <li class="step reveal" style="--d:0ms">
                <span class="step__num">1</span>
                <h3>Submit</h3>
                <p>Your billing system or POS sends each invoice through the API. Corrections, such as credit and debit notes, can also be raised in the console.</p>
                <div class="step__mock" aria-hidden="true">
<div class="code"><span class="c-n">POST</span> /api/v1/invoices
<span class="c-m">{</span>
  <span class="c-k">"supplier"</span>: <span class="c-m">{ … }</span>,
  <span class="c-k">"currency"</span>: <span class="c-s">"NAD"</span>,
  <span class="c-k">"lines"</span>: <span class="c-m">[ … ]</span>
<span class="c-m">}</span></div>
                </div>
            </li>
            <li class="step reveal" style="--d:90ms">
                <span class="step__num">2</span>
                <h3>Validate and certify</h3>
                <p>Totals, currency, VAT categories and supplier authority are checked. A valid invoice receives a fingerprint, a certificate and a verification link.</p>
                <div class="step__mock" aria-hidden="true">
                    <div class="tick"><i><x-site.icon name="check" /></i> Totals reconcile</div>
                    <div class="tick"><i><x-site.icon name="check" /></i> Currency is NAD</div>
                    <div class="tick"><i><x-site.icon name="check" /></i> VAT category on every line</div>
                    <div class="tick"><i><x-site.icon name="check" /></i> Supplier is authorised</div>
                    <div class="tick tick--end"><x-site.icon name="file-check" :size="16" /> Certificate issued</div>
                </div>
            </li>
            <li class="step reveal" style="--d:180ms">
                <span class="step__num">3</span>
                <h3>Report and reconcile</h3>
                <p>Certified invoices feed VAT periods and returns. Supplier and customer records are matched, and mismatches become exceptions with a severity and an owner.</p>
                <div class="step__mock" aria-hidden="true">
                    <div class="pair"><div class="pill">Supplier INV-4218 <small>11,500</small></div><span class="link-line"></span><div class="pill">Customer <small>11,500</small></div></div>
                    <div class="pair"><div class="pill">Supplier INV-4221 <small>3,220</small></div><span class="link-line link-line--bad"></span><div class="pill pill--bad">No match <small>High</small></div></div>
                </div>
            </li>
            <li class="step reveal" style="--d:270ms">
                <span class="step__num">4</span>
                <h3>Verify and audit</h3>
                <p>Customers and officers can verify a certificate in seconds. Every action lands in an audit trail that can be checked for tampering.</p>
                <div class="step__mock" aria-hidden="true">
                    <div class="chainmini">
                        <div class="blk"><b>#0412</b>9f2c…</div><span class="lnk"></span>
                        <div class="blk"><b>#0413</b>41ab…</div><span class="lnk"></span>
                        <div class="blk"><b>#0414</b>c07e…</div>
                    </div>
                    <div class="tick tick--end"><x-site.icon name="shield-check" :size="16" /> Chain verified</div>
                </div>
            </li>
        </ol>
    </div>
</section>

{{-- ======================= Platform ======================= --}}
<section class="section" id="platform" aria-labelledby="platform-title">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">The platform</span>
            <h2 class="h2" id="platform-title">One system for the <em class="accent">whole VAT lifecycle.</em></h2>
            <p class="lead">Certification, returns, reconciliation and oversight share one record of truth, so nobody re-keys data or argues over which version is right.</p>
        </div>

        <div class="bento">
            <article class="tile tile--w3 tile--tall reveal">
                <span class="tile__ico"><x-site.icon name="file-check" /></span>
                <h3>Invoice certification</h3>
                <p>Totals, currency, VAT categories and supplier authority are checked on every invoice. Standard, zero-rated, exempt, outside-scope and reverse-charge supplies are all handled, and a repeated submission never creates a duplicate.</p>
                <div class="tile__vis" aria-hidden="true">
                    <div class="mini">
                        <div class="mini__row"><span class="t mono">IN********18</span><span class="s">NAD 11,500.00</span><span class="chip chip--ok"><span class="dot"></span> Certified</span></div>
                        <div class="mini__row"><span class="t mono">IN********19</span><span class="s">NAD 2,070.00</span><span class="chip chip--ok"><span class="dot"></span> Certified</span></div>
                        <div class="mini__row"><span class="t mono">CN********04</span><span class="s">Credit note &middot; linked</span><span class="chip chip--info"><span class="dot"></span> Correction</span></div>
                    </div>
                </div>
                <a class="link-arrow" href="{{ route('site.product') }}#certification">Invoice certification <x-site.icon name="arrow-right" :size="15" /></a>
            </article>

            <article class="tile tile--w3 reveal" style="--d:80ms">
                <span class="tile__ico"><x-site.icon name="clock" /></span>
                <h3>VAT periods and returns</h3>
                <p>Returns are generated from certified transactions, with adjustments, an approval step and a recorded submission.</p>
                <div class="tile__vis" aria-hidden="true">
                    <div class="mini">
                        <div class="mini__row"><span class="t">Return readiness</span><span class="s">Sample period</span></div>
                        <div class="meter" style="margin-top:10px"><i style="width:82%"></i></div>
                    </div>
                </div>
            </article>

            <article class="tile tile--w3 reveal" style="--d:140ms">
                <span class="tile__ico"><x-site.icon name="scale" /></span>
                <h3>Reconciliation engine</h3>
                <p>Supplier and customer invoices are matched automatically. What does not match becomes an exception, with a severity, an age and an officer.</p>
                <div class="tile__vis" aria-hidden="true">
                    <div class="bars"><i style="height:88%"></i><i style="height:62%"></i><i style="height:74%"></i><i style="height:38%"></i><i style="height:54%"></i><i style="height:26%"></i><i style="height:44%"></i></div>
                </div>
            </article>

            <article class="tile reveal">
                <span class="tile__ico"><x-site.icon name="radar" /></span>
                <h3>Compliance and oversight</h3>
                <p>Risk indicators, audit cases with evidence and findings, obligations and taxpayer disputes in one workspace.</p>
            </article>

            <article class="tile reveal" style="--d:80ms">
                <span class="tile__ico"><x-site.icon name="wallet" /></span>
                <h3>Refunds and adjustments</h3>
                <p>Refund claims move through risk review, officer review and payment authorisation, with disputes raised in flow.</p>
            </article>

            <article class="tile reveal" style="--d:140ms">
                <span class="tile__ico"><x-site.icon name="chart" /></span>
                <h3>Reports and analytics</h3>
                <p>Governed report definitions, small-cell suppression, and CSV exports that need an approval before they leave.</p>
            </article>

            <article class="tile tile--w3 reveal">
                <span class="tile__ico"><x-site.icon name="layers" /></span>
                <h3>Business operations</h3>
                <p>The day-to-day records behind your VAT, kept alongside it, so evidence sits next to the numbers it supports.</p>
                <div class="tile__vis" aria-hidden="true">
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        <span class="chip">Expenses &amp; receipts</span><span class="chip">Inventory</span><span class="chip">Quotations</span><span class="chip">Purchase orders</span><span class="chip">Budgets</span><span class="chip">Projects</span><span class="chip">Fixed assets</span><span class="chip">Logistics</span>
                    </div>
                </div>
            </article>

            <article class="tile tile--w3 reveal" style="--d:80ms">
                <span class="tile__ico"><x-site.icon name="code" /></span>
                <h3>Integrations and API</h3>
                <p>Scoped API clients, credential rotation and revocation, conformance runs, a dedicated POS endpoint, and offline continuity for terminals.</p>
                <div class="tile__vis" aria-hidden="true">
                    <div class="mini"><div class="code"><span class="c-n">POST</span> /api/pos/v1/invoices</div></div>
                </div>
            </article>
        </div>

        <p style="margin-top:22px;font-size:.84rem;color:var(--muted)">Interface elements on this page are shown with sample data.</p>
    </div>
</section>

{{-- ======================= Audiences ======================= --}}
<section class="section section--sand" id="audiences" aria-labelledby="who-title">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">Who it is for</span>
            <h2 class="h2" id="who-title">Built for every role <em class="accent">in the VAT chain.</em></h2>
        </div>

        <div data-tabs class="reveal">
            <div class="tablist" role="tablist" aria-label="Choose an audience">
                <button class="tab" role="tab" id="tab-biz" aria-controls="panel-biz" aria-selected="true">Businesses</button>
                <button class="tab" role="tab" id="tab-auth" aria-controls="panel-auth" aria-selected="false" tabindex="-1"><span class="tab__long">Tax authorities</span><span class="tab__short">Authorities</span></button>
                <button class="tab" role="tab" id="tab-dev" aria-controls="panel-dev" aria-selected="false" tabindex="-1"><span class="tab__long">Developers &amp; partners</span><span class="tab__short">Developers</span></button>
            </div>

            <div class="tabpanel" role="tabpanel" id="panel-biz" aria-labelledby="tab-biz">
                <div>
                    <h3>File with confidence, from certified numbers.</h3>
                    <p>Your VAT position is built from invoices that have already been validated and certified, so the return reflects what actually happened.</p>
                    <ul class="checklist">
                        <li><i><x-site.icon name="check" /></i><span>Certify invoices and raise credit and debit notes against them</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Capture expenses with receipt evidence and a recorded decision</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Generate, adjust, approve and submit VAT returns</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Manage your staff, branches and roles, with approvals where you need them</span></li>
                    </ul>
                    <a class="link-arrow" href="{{ route('signup.create') }}">Request access <x-site.icon name="arrow-right" :size="15" /></a>
                </div>
                <div class="window" aria-hidden="true">
                    <div class="window__bar"><i></i><i></i><i></i><span>Your VAT position</span><em>Sample data</em></div>
                    <div class="window__body">
                        <div class="kpis">
                            <div class="kpi"><small>Output VAT</small><b>42,310</b></div>
                            <div class="kpi"><small>Input VAT</small><b>18,940</b></div>
                            <div class="kpi"><small>Net payable</small><b>23,370</b></div>
                        </div>
                        <div class="rows">
                            <div class="r r--head"><span>Invoice</span><span>Amount</span><span>Status</span></div>
                            <div class="r"><b class="mono">IN********18</b><span>NAD 11,500.00</span><span class="chip chip--ok"><span class="dot"></span> Certified</span></div>
                            <div class="r"><b class="mono">IN********19</b><span>NAD 2,070.00</span><span class="chip chip--ok"><span class="dot"></span> Certified</span></div>
                            <div class="r"><b class="mono">IN********21</b><span>NAD 3,220.00</span><span class="chip chip--warn"><span class="dot"></span> Exception</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tabpanel" role="tabpanel" id="panel-auth" aria-labelledby="tab-auth">
                <div>
                    <h3>Oversee the whole base, not a sample of it.</h3>
                    <p>National-scope roles see every certified invoice and every exception in one place, with the controls to act on them and a record of having done so.</p>
                    <ul class="checklist">
                        <li><i><x-site.icon name="check" /></i><span>Reconciliation exceptions with severity, ageing and officer assignment</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Risk indicators that can be escalated into audit cases</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Obligations, disputes and refund claims in governed workflows</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Approval-gated exports and a security operations centre</span></li>
                    </ul>
                    <a class="link-arrow" href="{{ route('site.product') }}#oversight">Compliance &amp; oversight <x-site.icon name="arrow-right" :size="15" /></a>
                </div>
                <div class="window" aria-hidden="true">
                    <div class="window__bar"><i></i><i></i><i></i><span>National oversight</span><em>Sample data</em></div>
                    <div class="window__body">
                        <div class="kpis">
                            <div class="kpi"><small>Open exceptions</small><b>14</b></div>
                            <div class="kpi"><small>Critical</small><b>3</b></div>
                            <div class="kpi"><small>Risk signals</small><b>27</b></div>
                        </div>
                        <div class="rows">
                            <div class="r r--head"><span>Exception</span><span>Age</span><span>Severity</span></div>
                            <div class="r"><b>Unmatched supplier invoice</b><span>6 days</span><span class="chip chip--bad"><span class="dot"></span> Critical</span></div>
                            <div class="r"><b>Amount mismatch</b><span>3 days</span><span class="chip chip--warn"><span class="dot"></span> High</span></div>
                            <div class="r"><b>Duplicate reference</b><span>1 day</span><span class="chip chip--info"><span class="dot"></span> Medium</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tabpanel" role="tabpanel" id="panel-dev" aria-labelledby="tab-dev">
                <div>
                    <h3>Connect your billing system without guesswork.</h3>
                    <p>Machine access is deny-by-default: every client is scoped, its credentials are rotated and revoked through the platform, and responses carry everything you need to print on the invoice.</p>
                    <ul class="checklist">
                        <li><i><x-site.icon name="check" /></i><span>A JSON API and a dedicated POS endpoint</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Idempotent submission, so retries are always safe</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Credential rotation, revocation and conformance runs</span></li>
                        <li><i><x-site.icon name="check" /></i><span>Offline continuity with enrolled devices and reserved number ranges</span></li>
                    </ul>
                    <a class="link-arrow" href="{{ route('site.product') }}#integrations">Integrations <x-site.icon name="arrow-right" :size="15" /></a>
                </div>
                <div class="window window--dark" aria-hidden="true">
                    <div class="window__bar"><i></i><i></i><i></i><span>Certified invoice response</span><em>Sample data</em></div>
<div class="codeblock"><span class="c-m">{</span>
  <span class="c-k">"status"</span>: <span class="c-s">"CERTIFIED"</span>,
  <span class="c-k">"currency"</span>: <span class="c-s">"NAD"</span>,
  <span class="c-k">"total_cents"</span>: <span class="c-n">1150000</span>,
  <span class="c-k">"verification_url"</span>: <span class="c-s">"https://…/verify/vfy_…"</span>,
  <span class="c-k">"qr_payload"</span>: <span class="c-s">"https://…/verify/vfy_…"</span>
<span class="c-m">}</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ======================= Verification ======================= --}}
<section class="section" id="verify" aria-labelledby="verify-title">
    <div class="wrap spotlight">
        <div class="reveal">
            <span class="eyebrow">Public verification</span>
            <h2 class="h2" id="verify-title" style="margin-top:18px">Anyone can check an invoice. <em class="accent">No account needed.</em></h2>
            <p class="lead" style="margin-top:18px">Every certified invoice carries a verification link and a QR payload. Paste the code or link below to see the certificate's status, the supplier, a masked invoice number and the gross amount. Line items, customer details and correction reasons are never shown.</p>

            @include('site.partials.lookup', ['id' => 'home-code'])

            <p class="lookup__hint">Certificates issued during the pilot are pilot certificates. Production legal signatures require the approved signing profile and protected signing keys.</p>
        </div>

        <div class="reveal" style="--d:120ms" aria-hidden="true">
            <div class="verifycard">
                <div class="verifycard__top">
                    <span class="seal"><x-site.icon name="check" /></span>
                    <small>VAT-MSA certificate verification</small>
                    <h3>Valid pilot certificate</h3>
                </div>
                <dl>
                    <div><dt>Supplier</dt><dd>Sample Trading (Pty) Ltd</dd></div>
                    <div><dt>Invoice</dt><dd class="mono">IN********18</dd></div>
                    <div><dt>Gross amount</dt><dd>NAD 11,500.00</dd></div>
                    <div><dt>Status</dt><dd>VALID</dd></div>
                    <div><dt>Certified at</dt><dd>24 Oct 2026, 09:41</dd></div>
                </dl>
                <p class="verifycard__note">Privacy-minimised verification. Full taxpayer invoice data is not disclosed. Illustrative sample.</p>
            </div>
        </div>
    </div>
</section>

{{-- ======================= Security ======================= --}}
<section class="section section--ink" id="security" aria-labelledby="security-title">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">Security and trust</span>
            <h2 class="h2" id="security-title">Built to be audited, <em class="accent">not just trusted.</em></h2>
            <p class="lead">Controls you can inspect, described plainly, so a security review starts from facts.</p>
        </div>

        <div class="controls reveal">
            <div class="control">
                <span class="control__ico"><x-site.icon name="key" /></span>
                <h3>Step-up authentication</h3>
                <p>Sensitive actions, such as cancelling an invoice, deciding a registration or approving an export, need a fresh authenticator-app code (TOTP, RFC 6238) that stays valid for five minutes.</p>
            </div>
            <div class="control">
                <span class="control__ico"><x-site.icon name="link" /></span>
                <h3>Tamper-evident audit trail</h3>
                <p>Every significant action is appended to a hash-chained ledger. Integrity can be verified on demand, and each verification run is recorded.</p>
            </div>
            <div class="control">
                <span class="control__ico"><x-site.icon name="users" /></span>
                <h3>Roles, scopes and approvals</h3>
                <p>Built-in roles with granular permissions, national, regional and office scopes, and organisation-defined roles that policy keeps within safe limits.</p>
            </div>
            <div class="control">
                <span class="control__ico"><x-site.icon name="shield-check" /></span>
                <h3>Abuse protection</h3>
                <p>Per-user, per-device, per-source and platform-wide rate limits on writes and on public endpoints, plus a security operations centre with detection rules and an incident workflow.</p>
            </div>
            <div class="control">
                <span class="control__ico"><x-site.icon name="eye" /></span>
                <h3>Privacy-minimised verification</h3>
                <p>Public checks show status, supplier, a masked invoice number and the gross amount. Never line items, customer details or correction reasons.</p>
            </div>
            <div class="control">
                <span class="control__ico"><x-site.icon name="plug" /></span>
                <h3>Fail-closed integrations</h3>
                <p>Connectors to authority and customs systems stay disabled until a technical contract is confirmed. The platform never fabricates third-party data.</p>
            </div>
        </div>

        <div class="chain reveal" aria-hidden="true">
            <div class="block"><b>Entry #0412</b>prev <span>7a3e…19c0</span>hash <span>9f2c…b71e</span></div>
            <span class="chain__link"></span>
            <div class="block"><b>Entry #0413</b>prev <span>9f2c…b71e</span>hash <span>41ab…06d3</span></div>
            <span class="chain__link"></span>
            <div class="block"><b>Entry #0414</b>prev <span>41ab…06d3</span>hash <span>c07e…5a92</span></div>
            <span class="chain__link"></span>
            <div class="block"><b>Entry #0415</b>prev <span>c07e…5a92</span>hash <span>e5d1…8f40</span></div>
        </div>
        <div class="chain__status">
            <span class="chip chip--glass"><span class="dot" style="color:#35D39B"></span> Illustrative: chain intact</span>
            <a class="link-arrow" href="{{ route('site.trust') }}">Read the security overview <x-site.icon name="arrow-right" :size="15" /></a>
        </div>
    </div>
</section>

{{-- ======================= Access ======================= --}}
<section class="section section--sand" id="access" aria-labelledby="access-title">
    <div class="wrap access">
        <div class="reveal">
            <span class="eyebrow">Pilot access</span>
            <h2 class="h2" id="access-title" style="margin-top:18px">Join the pilot, <em class="accent">by application.</em></h2>
            <p class="lead" style="margin-top:18px">VAT-MSA is offered through a controlled pilot. Your application is reviewed by administrators before any account or licence is activated.</p>
            <ol class="process">
                <li><b>Apply</b><span>Tell us about your organisation: VAT number, TIN, legal name and your authority to apply.</span></li>
                <li><b>Get verified</b><span>Administrators verify the application and your organisation. Nothing is activated meanwhile.</span></li>
                <li><b>Go live</b><span>Once approved, your licence is activated and your team is invited in.</span></li>
            </ol>
        </div>

        <div class="reveal" style="--d:120ms">
            @include('site.partials.plan-card', ['plans' => $plans, 'compact' => true])
        </div>
    </div>
</section>

{{-- ======================= FAQ ======================= --}}
<section class="section" id="faq" aria-labelledby="faq-title">
    <div class="wrap">
        <div class="section-head section-head--center reveal">
            <span class="eyebrow">Questions</span>
            <h2 class="h2" id="faq-title">Straight answers.</h2>
        </div>

        <div class="faq reveal">
            @include('site.partials.faq')
        </div>
    </div>
</section>

{{-- ======================= Closing call to action ======================= --}}
<section class="cta" aria-labelledby="cta-title">
    <div class="wrap">
        <span class="eyebrow" style="color:var(--gold)">Get started</span>
        <h2 id="cta-title" style="margin-top:20px">Bring certainty to <em class="accent" style="color:var(--gold)">every invoice.</em></h2>
        <p class="lead">Apply for pilot access, or sign in if your organisation already has an account.</p>
        <div class="cta__actions">
            <a class="btn btn--gold btn--lg" href="{{ route('signup.create') }}">Request access <x-site.icon name="arrow-right" :size="18" /></a>
            <a class="btn btn--ghost-inverse btn--lg" href="{{ route('login') }}">Sign in</a>
        </div>
    </div>
    <div class="sunset" aria-hidden="true"></div>
    @include('site.partials.dunes')
</section>

@endsection
