@extends('site.layout')

@section('title', 'Security and trust — VAT-MSA')
@section('description', 'How VAT-MSA protects access, keeps records honest and limits what it exposes: step-up authentication, a hash-chained audit trail, rate limits, privacy-minimised verification, and a plain statement of what the pilot does not yet do.')

@section('content')
@php $contactEmail = config('site.contact_email'); @endphp

<section class="pagehero" aria-labelledby="trust-title">
    <div class="wrap">
        <span class="eyebrow">Security and trust</span>
        <h1 id="trust-title">Security you can inspect, <em class="accent">not just trust.</em></h1>
        <p class="lead">This is what VAT-MSA does to protect access, keep records honest and limit what it exposes, and where the pilot still has limits. It describes controls built into the platform. It is not an independent audit or certification.</p>
    </div>
</section>

{{-- Identity and access --}}
<section class="section--tight" aria-labelledby="t-access">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">Identity and access</span>
            <h2 class="h2" id="t-access">Only the right people, <em class="accent">for the right actions.</em></h2>
        </div>
        <div class="cards">
            <article class="card reveal">
                <span class="card__ico"><x-site.icon name="lock" /></span>
                <h3>Sign-in and sessions</h3>
                <p>Passwords are stored only as one-way hashes. Resetting a password signs out every other session, and you can list and revoke your own active sessions at any time.</p>
            </article>
            <article class="card reveal" style="--d:90ms">
                <span class="card__ico"><x-site.icon name="key" /></span>
                <h3>Step-up authentication</h3>
                <p>Cancelling an invoice, deciding a registration, assigning access, changing a licence, approving an export or authorising a refund payment needs a fresh authenticator-app code (TOTP, RFC 6238).</p>
                <ul>
                    <li>Valid for five minutes</li>
                    <li>No password fallback</li>
                </ul>
            </article>
            <article class="card reveal" style="--d:180ms">
                <span class="card__ico"><x-site.icon name="users" /></span>
                <h3>Roles, scopes and approvals</h3>
                <p>Built-in roles carry granular permissions. Access can be scoped nationally, regionally or to an office, and organisations can define their own roles, which policy keeps within safe limits.</p>
            </article>
        </div>
    </div>
</section>

{{-- Integrity --}}
<section class="section section--ink" aria-labelledby="t-integrity">
    <div class="wrap spotlight">
        <div class="reveal">
            <span class="eyebrow">Integrity of records</span>
            <h2 class="h2" id="t-integrity" style="margin-top:18px">History that <em class="accent">cannot be quietly rewritten.</em></h2>
            <p class="lead" style="margin-top:18px">Every significant action is appended to an audit ledger in which each entry carries the hash of the one before it. Changing an old entry breaks every entry after it.</p>
            <ul class="checklist checklist--light">
                <li><i><x-site.icon name="check" /></i><span>Integrity can be verified on demand, and each verification run is itself recorded</span></li>
                <li><i><x-site.icon name="check" /></i><span>A repeated command is recognised and answered, never executed twice</span></li>
                <li><i><x-site.icon name="check" /></i><span>Events are committed in the same transaction as the change that caused them</span></li>
            </ul>
        </div>
        <div class="reveal" style="--d:120ms" aria-hidden="true">
            <div class="chain chain--stack">
                <div class="block"><b>Entry #0412</b>prev <span>7a3e…19c0</span>hash <span>9f2c…b71e</span></div>
                <span class="chain__link"></span>
                <div class="block"><b>Entry #0413</b>prev <span>9f2c…b71e</span>hash <span>41ab…06d3</span></div>
                <span class="chain__link"></span>
                <div class="block"><b>Entry #0414</b>prev <span>41ab…06d3</span>hash <span>c07e…5a92</span></div>
            </div>
            <p class="lookup__hint" style="text-align:center">Illustrative example of how entries link.</p>
        </div>
    </div>
</section>

{{-- Operational protection --}}
<section class="section" aria-labelledby="t-ops">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">Operational protection</span>
            <h2 class="h2" id="t-ops">Defence <em class="accent">in layers.</em></h2>
        </div>
        <div class="cards">
            <article class="card reveal">
                <span class="card__ico"><x-site.icon name="shield-check" /></span>
                <h3>Abuse protection</h3>
                <p>Rate limits apply per user, per device, per source and across the platform, on write actions and on public endpoints such as signup, invitation claims and invoice verification.</p>
            </article>
            <article class="card reveal" style="--d:90ms">
                <span class="card__ico"><x-site.icon name="radar" /></span>
                <h3>Security operations</h3>
                <p>Detection rules, a record of every denied authorisation, and an incident workflow with containment, access revocation and closure, each step needing step-up authentication.</p>
            </article>
            <article class="card reveal" style="--d:180ms">
                <span class="card__ico"><x-site.icon name="server" /></span>
                <h3>Transport and browser safeguards</h3>
                <p>Every response carries strict-transport-security, frame-denial, content-type and referrer-policy headers, so browsers are told to use HTTPS and refuse to be framed.</p>
            </article>
        </div>
    </div>
</section>

{{-- Privacy --}}
<section class="section section--sand" aria-labelledby="t-privacy">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">Privacy by design</span>
            <h2 class="h2" id="t-privacy">Verification that shows <em class="accent">only what it must.</em></h2>
            <p class="lead">Anyone can check a certificate without an account, so what the public check returns is deliberately narrow.</p>
        </div>
        <div class="sees">
            <article class="card yes reveal">
                <h3><i><x-site.icon name="check" :size="14" /></i> What anyone can see</h3>
                <ul>
                    <li>The certificate's status</li>
                    <li>The supplier's name</li>
                    <li>A masked invoice number, such as <span class="mono">IN********18</span></li>
                    <li>The gross amount and currency</li>
                    <li>When it was certified, and its fingerprint</li>
                    <li>Corrections issued against it</li>
                </ul>
            </article>
            <article class="card no reveal" style="--d:90ms">
                <h3><i><x-site.icon name="x" :size="14" /></i> What is never shown</h3>
                <ul>
                    <li>Line items and descriptions</li>
                    <li>The customer's details</li>
                    <li>The reason given for a correction</li>
                    <li>The full invoice number</li>
                    <li>Anything from the audit trail</li>
                </ul>
            </article>
        </div>
        <p class="lookup__hint reveal" style="margin-top:22px">Reports add a second safeguard: figures below a minimum group size are suppressed, so an aggregate cannot point to one taxpayer.</p>
    </div>
</section>

{{-- Pilot status --}}
<section class="section" id="pilot" aria-labelledby="t-pilot">
    <div class="wrap wrap--narrow">
        <div class="callout reveal">
            <span class="callout__ico"><x-site.icon name="alert" /></span>
            <div>
                <h3 id="t-pilot">Pilot status: what is not live yet</h3>
                <p>VAT-MSA is in pilot. We would rather say plainly what that means than let a page imply more.</p>
                <ul class="checklist" style="margin-top:16px">
                    <li><i><x-site.icon name="alert" :size="12" /></i><span><b>Certificates are pilot certificates.</b> Production legal signatures require an approved signing profile and protected signing keys, which are not part of the pilot.</span></li>
                    <li><i><x-site.icon name="alert" :size="12" /></i><span><b>Authority and customs connectors are off.</b> They stay disabled until a technical contract is confirmed. VAT-MSA does not pull, or invent, data from those systems.</span></li>
                    <li><i><x-site.icon name="alert" :size="12" /></i><span><b>Payment connectors are off.</b> No money moves through the platform until an authority contract exists.</span></li>
                    <li><i><x-site.icon name="alert" :size="12" /></i><span><b>Outbound webhook delivery is not live.</b> Events are stored durably instead of being marked delivered.</span></li>
                </ul>
                @if ($contactEmail)
                    <p style="margin-top:20px">To report a security concern, email <a href="mailto:{{ $contactEmail }}" style="border-bottom:1.5px solid var(--gold-deep);font-weight:600">{{ $contactEmail }}</a>.</p>
                @endif
            </div>
        </div>
    </div>
</section>

@include('site.partials.cta', ['title' => 'Review it from <em class="accent" style="color:var(--gold)">the inside.</em>', 'lead' => 'Apply for pilot access, or sign in if your organisation already has an account.'])

@endsection
