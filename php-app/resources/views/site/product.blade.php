@extends('site.layout')

@section('title', 'Product — VAT-MSA')
@section('description', 'Invoice certification, VAT returns, reconciliation, compliance oversight, refunds, business operations, governed reports and a developer platform: the whole VAT lifecycle in one system.')

@section('content')

<section class="pagehero" aria-labelledby="product-title">
    <div class="wrap">
        <span class="eyebrow">The platform</span>
        <h1 id="product-title">Everything between an invoice <em class="accent">and an audit.</em></h1>
        <p class="lead">One system carries an invoice from submission to certificate, into the return, through reconciliation and, if it is ever needed, an audit, with the same record of truth at every step.</p>
        <div class="hero__actions" style="margin-top:32px">
            <a class="btn btn--lg" href="{{ route('signup.create') }}">Request access <x-site.icon name="arrow-right" :size="18" /></a>
            <a class="btn btn--ghost btn--lg" href="{{ route('login') }}">Sign in</a>
        </div>
    </div>
</section>

<nav class="subnav" aria-label="On this page">
    <div class="wrap subnav__inner">
        <a href="#certification">Certification</a>
        <a href="#returns">Returns</a>
        <a href="#reconciliation">Reconciliation</a>
        <a href="#oversight">Oversight</a>
        <a href="#refunds">Refunds</a>
        <a href="#operations">Operations</a>
        <a href="#reports">Reports</a>
        <a href="#integrations">Integrations</a>
    </div>
</nav>

<div class="wrap">

    {{-- 1 --}}
    <section class="module" id="certification" aria-labelledby="m1">
        <div class="module__copy reveal">
            <span class="module__num">01</span>
            <h2 id="m1">Invoice certification</h2>
            <p>Every invoice is validated before it is certified. Totals must reconcile, the currency must match the jurisdiction, every line needs a VAT category, and the supplier must be an authorised, active organisation.</p>
            <ul class="checklist">
                <li><i><x-site.icon name="check" /></i><span>Standard (15%), zero-rated, exempt, outside-scope and reverse-charge categories</span></li>
                <li><i><x-site.icon name="check" /></i><span>Idempotent submission, so a retry never creates a duplicate</span></li>
                <li><i><x-site.icon name="check" /></i><span>A unique fingerprint, a certificate and a verification link with a QR payload for every invoice</span></li>
                <li><i><x-site.icon name="check" /></i><span>Credit and debit notes linked to the invoice they correct</span></li>
                <li><i><x-site.icon name="check" /></i><span>Cancellation is a step-up action and stays in the audit trail</span></li>
            </ul>
        </div>
        <div class="module__vis reveal" style="--d:100ms" aria-hidden="true">
            <div class="window">
                <div class="window__bar"><i></i><i></i><i></i><span>Invoice IN********20</span><em>Sample data</em></div>
                <div class="window__body">
                    <div class="rows">
                        <div class="r r--head"><span>Line</span><span>VAT category</span><span>Net</span></div>
                        <div class="r"><b>Consulting services</b><span>Standard &middot; 15%</span><span>6,000.00</span></div>
                        <div class="r"><b>Export documentation</b><span>Zero-rated</span><span>2,000.00</span></div>
                        <div class="r"><b>Training materials</b><span>Exempt</span><span>1,000.00</span></div>
                    </div>
                    <div class="totals">
                        <div><span>Net</span><b>NAD 9,000.00</b></div>
                        <div><span>VAT</span><b>NAD 900.00</b></div>
                        <div class="totals__sum"><span>Gross</span><b>NAD 9,900.00</b></div>
                    </div>
                    <div class="step__mock" style="margin-top:14px;min-height:0;box-shadow:none">
                        <div class="tick"><i><x-site.icon name="check" /></i> Totals reconcile</div>
                        <div class="tick"><i><x-site.icon name="check" /></i> Supplier is authorised</div>
                        <div class="tick tick--end"><x-site.icon name="file-check" :size="16" /> Certificate issued</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 2 --}}
    <section class="module" id="returns" aria-labelledby="m2">
        <div class="module__copy reveal">
            <span class="module__num">02</span>
            <h2 id="m2">VAT periods and returns</h2>
            <p>Returns are generated from the transactions you have certified, so every figure can be traced back to the invoices behind it.</p>
            <ul class="checklist">
                <li><i><x-site.icon name="check" /></i><span>Monthly, bi-monthly, quarterly or annual return frequency</span></li>
                <li><i><x-site.icon name="check" /></i><span>Adjustments recorded against a period, each with its reason</span></li>
                <li><i><x-site.icon name="check" /></i><span>An approval request and a decision before anything is submitted</span></li>
                <li><i><x-site.icon name="check" /></i><span>VAT summary, adjustment, audit and reconciliation reports</span></li>
            </ul>
        </div>
        <div class="module__vis reveal" style="--d:100ms" aria-hidden="true">
            <div class="window">
                <div class="window__bar"><i></i><i></i><i></i><span>VAT return</span><em>Sample data</em></div>
                <div class="window__body">
                    <div class="kpis">
                        <div class="kpi"><small>Output VAT</small><b>42,310</b></div>
                        <div class="kpi"><small>Input VAT</small><b>18,940</b></div>
                        <div class="kpi"><small>Net payable</small><b>23,370</b></div>
                    </div>
                    <div class="flow">
                        <span class="chip chip--ok"><span class="dot"></span> Generated</span><i></i>
                        <span class="chip chip--ok"><span class="dot"></span> Adjusted</span><i></i>
                        <span class="chip chip--ok"><span class="dot"></span> Approved</span><i></i>
                        <span class="chip chip--warn"><span class="dot"></span> To submit</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 3 --}}
    <section class="module" id="reconciliation" aria-labelledby="m3">
        <div class="module__copy reveal">
            <span class="module__num">03</span>
            <h2 id="m3">Reconciliation and exceptions</h2>
            <p>Supplier and customer records are matched automatically. Whatever does not match becomes an exception that somebody owns, instead of a surprise at audit time.</p>
            <ul class="checklist">
                <li><i><x-site.icon name="check" /></i><span>Severity from low to critical, and an age that keeps growing until it is resolved</span></li>
                <li><i><x-site.icon name="check" /></i><span>A work queue with filters, officer assignment and resolution notes</span></li>
                <li><i><x-site.icon name="check" /></i><span>Open count, critical count and total exception value at a glance</span></li>
                <li><i><x-site.icon name="check" /></i><span>Resolving an exception is a step-up action, recorded in the audit trail</span></li>
            </ul>
        </div>
        <div class="module__vis reveal" style="--d:100ms" aria-hidden="true">
            <div class="window">
                <div class="window__bar"><i></i><i></i><i></i><span>Exceptions work queue</span><em>Sample data</em></div>
                <div class="window__body">
                    <div class="kpis">
                        <div class="kpi"><small>Open</small><b>14</b></div>
                        <div class="kpi"><small>Critical</small><b>3</b></div>
                        <div class="kpi"><small>Exception value</small><b>128,400</b></div>
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
    </section>

    {{-- 4 --}}
    <section class="module" id="oversight" aria-labelledby="m4">
        <div class="module__copy reveal">
            <span class="module__num">04</span>
            <h2 id="m4">Compliance and oversight</h2>
            <p>National-scope roles oversee the whole base from one workspace, while a taxpayer sees only their own obligations and disputes.</p>
            <ul class="checklist">
                <li><i><x-site.icon name="check" /></i><span>Risk indicators from a fixed, versioned rule catalogue, reviewed and escalated into audit cases</span></li>
                <li><i><x-site.icon name="check" /></i><span>Audit cases with evidence, notes and findings</span></li>
                <li><i><x-site.icon name="check" /></i><span>Obligations tracked as pending, satisfied or overdue</span></li>
                <li><i><x-site.icon name="check" /></i><span>Disputes filed and followed in one place</span></li>
            </ul>
        </div>
        <div class="module__vis reveal" style="--d:100ms" aria-hidden="true">
            <div class="window">
                <div class="window__bar"><i></i><i></i><i></i><span>Risk indicators</span><em>Sample data</em></div>
                <div class="window__body">
                    <div class="rows" style="margin-top:0">
                        <div class="r r--head"><span>Indicator</span><span>Status</span><span>Severity</span></div>
                        <div class="r"><b>High-Value Invoice Pattern</b><span>Under review</span><span class="chip chip--warn"><span class="dot"></span> High</span></div>
                        <div class="r"><b>Reconciliation Exception Backlog</b><span>Open</span><span class="chip chip--info"><span class="dot"></span> Medium</span></div>
                        <div class="r"><b>Obligation Overdue</b><span>Escalated to case</span><span class="chip chip--bad"><span class="dot"></span> Critical</span></div>
                    </div>
                    <div class="flow">
                        <span class="chip">Risk indicator</span><i></i>
                        <span class="chip chip--info"><span class="dot"></span> Review</span><i></i>
                        <span class="chip chip--warn"><span class="dot"></span> Audit case</span><i></i>
                        <span class="chip chip--ok"><span class="dot"></span> Findings</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 5 --}}
    <section class="module" id="refunds" aria-labelledby="m5">
        <div class="module__copy reveal">
            <span class="module__num">05</span>
            <h2 id="m5">Refunds and adjustments</h2>
            <p>A refund claim starts from a filed return and moves through reviewed stages, so no claim is paid on the strength of a single approval.</p>
            <ul class="checklist">
                <li><i><x-site.icon name="check" /></i><span>Claims raised directly from a VAT return</span></li>
                <li><i><x-site.icon name="check" /></i><span>Risk review, officer review and payment authorisation as separate stages</span></li>
                <li><i><x-site.icon name="check" /></i><span>Payment steps need a fresh authenticator code</span></li>
                <li><i><x-site.icon name="check" /></i><span>Disputes raised in the flow of the claim</span></li>
                <li><i><x-site.icon name="check" /></i><span>Payment connectors stay disabled until an authority contract exists</span></li>
            </ul>
        </div>
        <div class="module__vis reveal" style="--d:100ms" aria-hidden="true">
            <div class="window">
                <div class="window__bar"><i></i><i></i><i></i><span>Refund claim</span><em>Sample data</em></div>
                <div class="window__body">
                    <div class="kpis">
                        <div class="kpi"><small>Claimed</small><b>23,370</b></div>
                        <div class="kpi"><small>Stage</small><b style="font-size:1.05rem;line-height:1.3">Officer review</b></div>
                        <div class="kpi"><small>Raised from</small><b style="font-size:1.05rem;line-height:1.3">Return</b></div>
                    </div>
                    <div class="flow">
                        <span class="chip chip--ok"><span class="dot"></span> Risk review</span><i></i>
                        <span class="chip chip--warn"><span class="dot"></span> Officer review</span><i></i>
                        <span class="chip"><span class="dot"></span> Payment authorisation</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 6 --}}
    <section class="module" id="operations" aria-labelledby="m6">
        <div class="module__copy reveal">
            <span class="module__num">06</span>
            <h2 id="m6">Business operations</h2>
            <p>The records behind your VAT, kept alongside it, so evidence sits next to the numbers it supports.</p>
            <ul class="checklist">
                <li><i><x-site.icon name="check" /></i><span>Expenses with receipt evidence and a recorded approve or reject decision</span></li>
                <li><i><x-site.icon name="check" /></i><span>Inventory and warehouses, with movements and stock valuation</span></li>
                <li><i><x-site.icon name="check" /></i><span>Quotations, purchase orders and budgets</span></li>
                <li><i><x-site.icon name="check" /></i><span>Projects with costing, fixed assets and logistics deliveries</span></li>
                <li><i><x-site.icon name="check" /></i><span>Supplier and customer ledgers and cash-flow views</span></li>
            </ul>
        </div>
        <div class="module__vis reveal" style="--d:100ms" aria-hidden="true">
            <div class="window">
                <div class="window__bar"><i></i><i></i><i></i><span>Operations</span><em>Sample data</em></div>
                <div class="window__body">
                    <div class="rows" style="margin-top:0">
                        <div class="r r--head"><span>Record</span><span>Detail</span><span>Status</span></div>
                        <div class="r"><b>Expense</b><span>Fuel &middot; receipt attached</span><span class="chip chip--ok"><span class="dot"></span> Approved</span></div>
                        <div class="r"><b>Purchase order</b><span>PO-0042</span><span class="chip chip--info"><span class="dot"></span> Sent</span></div>
                        <div class="r"><b>Stock</b><span>Cement 50 kg &middot; 128 units</span><span class="chip chip--ok"><span class="dot"></span> In stock</span></div>
                        <div class="r"><b>Delivery</b><span>Windhoek &rarr; Walvis Bay</span><span class="chip chip--warn"><span class="dot"></span> In transit</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 7 --}}
    <section class="module" id="reports" aria-labelledby="m7">
        <div class="module__copy reveal">
            <span class="module__num">07</span>
            <h2 id="m7">Reports and analytics</h2>
            <p>Reports are defined once and governed: who may run them, who may export them, and which small numbers are hidden.</p>
            <ul class="checklist">
                <li><i><x-site.icon name="check" /></i><span>Report definitions scoped by audience: taxpayer, open data, tax-authority operations, executive, audit and legal, and practitioner</span></li>
                <li><i><x-site.icon name="check" /></i><span>Small-cell suppression, so aggregates cannot reveal a single taxpayer</span></li>
                <li><i><x-site.icon name="check" /></i><span>Runs can be published; CSV exports are requested, approved by an authorised role, then downloaded</span></li>
                <li><i><x-site.icon name="check" /></i><span>Approval needs a fresh authenticator code</span></li>
            </ul>
        </div>
        <div class="module__vis reveal" style="--d:100ms" aria-hidden="true">
            <div class="window">
                <div class="window__bar"><i></i><i></i><i></i><span>VAT position report</span><em>Sample data</em></div>
                <div class="window__body">
                    <div class="bars" style="margin-bottom:16px"><i style="height:48%"></i><i style="height:72%"></i><i style="height:56%"></i><i style="height:90%"></i><i style="height:64%"></i><i style="height:80%"></i><i style="height:52%"></i><i style="height:70%"></i></div>
                    <div class="flow">
                        <span class="chip chip--ok"><span class="dot"></span> Run complete</span><i></i>
                        <span class="chip chip--info"><span class="dot"></span> Export requested</span><i></i>
                        <span class="chip chip--warn"><span class="dot"></span> Pending approval</span><i></i>
                        <span class="chip"><span class="dot"></span> Download</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 8 --}}
    <section class="module" id="integrations" aria-labelledby="m8">
        <div class="module__copy reveal">
            <span class="module__num">08</span>
            <h2 id="m8">Integrations and developers</h2>
            <p>Machine access is deny-by-default: every client is scoped, and nothing connects to an outside authority system without a confirmed contract.</p>
            <ul class="checklist">
                <li><i><x-site.icon name="check" /></i><span>A JSON API and a dedicated POS endpoint</span></li>
                <li><i><x-site.icon name="check" /></i><span>Scoped API clients, with credentials held in an external secret manager</span></li>
                <li><i><x-site.icon name="check" /></i><span>Credential rotation, revocation and conformance runs</span></li>
                <li><i><x-site.icon name="check" /></i><span>Offline continuity: enrolled devices with verified keys, reserved number ranges and hash-chain checks on synchronisation</span></li>
                <li><i><x-site.icon name="check" /></i><span>Events recorded in the same transaction that caused them, through a transactional outbox</span></li>
                <li><i><x-site.icon name="check" /></i><span>Liveness and readiness endpoints for monitoring</span></li>
            </ul>
        </div>
        <div class="module__vis reveal" style="--d:100ms" aria-hidden="true">
            <div class="window window--dark">
                <div class="window__bar"><i></i><i></i><i></i><span>POS integration</span><em>Sample data</em></div>
<div class="codeblock"><span class="c-n">POST</span> /api/pos/v1/invoices
<span class="c-m">&rarr;</span> <span class="c-m">{</span>
  <span class="c-k">"status"</span>: <span class="c-s">"CERTIFIED"</span>,
  <span class="c-k">"verification_url"</span>: <span class="c-s">"https://…/verify/vfy_…"</span>,
  <span class="c-k">"qr_payload"</span>: <span class="c-s">"https://…/verify/vfy_…"</span>
<span class="c-m">}</span>

<span class="c-n">GET</span> /api/health/ready
<span class="c-m">&rarr;</span> <span class="c-m">{</span> <span class="c-k">"status"</span>: <span class="c-s">"READY"</span> <span class="c-m">}</span></div>
            </div>
        </div>
    </section>

</div>

@include('site.partials.cta', ['title' => 'See it with <em class="accent" style="color:var(--gold)">your own invoices.</em>', 'lead' => 'Apply for pilot access, or sign in if your organisation already has an account.'])

@endsection
