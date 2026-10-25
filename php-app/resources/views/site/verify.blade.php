@extends('site.layout')

@section('title', 'Verify an invoice — VAT-MSA')
@section('description', 'Check a VAT-MSA invoice certificate without an account. Paste the verification code or link from the invoice to see its status, supplier, masked invoice number and gross amount.')

@section('content')

<section class="pagehero pagehero--center" aria-labelledby="verify-title">
    <div class="wrap">
        <span class="eyebrow">Public verification</span>
        <h1 id="verify-title">Check an invoice <em class="accent">in seconds.</em></h1>
        <p class="lead">Paste the verification code or link printed on the invoice, or scan its QR code with your phone's camera. No account is needed.</p>
    </div>
</section>

<section class="section--tight" aria-label="Verification form">
    <div class="wrap">
        <div class="lookup-card reveal">
            @include('site.partials.lookup', ['id' => 'verify-code', 'code' => $code, 'error' => $error])
            <p class="lookup__hint">The code is printed on the invoice, usually beside a QR code. It is part of a link that ends in <span class="mono">/verify/</span> followed by the code, and you can paste either the whole link or just the code.</p>
        </div>
    </div>
</section>

<section class="section--tight" aria-labelledby="verify-sees">
    <div class="wrap">
        <div class="section-head section-head--center reveal">
            <h2 class="h3" id="verify-sees">What a check shows, and what it never does</h2>
        </div>
        <div class="sees">
            <article class="card yes reveal">
                <h3><i><x-site.icon name="check" :size="14" /></i> Shown</h3>
                <ul>
                    <li>The certificate's status</li>
                    <li>The supplier's name</li>
                    <li>A masked invoice number, such as <span class="mono">IN********18</span></li>
                    <li>The gross amount and currency</li>
                    <li>When it was certified, and its fingerprint</li>
                    <li>Any corrections issued against it</li>
                </ul>
            </article>
            <article class="card no reveal" style="--d:90ms">
                <h3><i><x-site.icon name="x" :size="14" /></i> Never shown</h3>
                <ul>
                    <li>Line items and descriptions</li>
                    <li>The customer's details</li>
                    <li>The reason given for a correction</li>
                    <li>The full invoice number</li>
                </ul>
            </article>
        </div>
    </div>
</section>

<section class="section--tight">
    <div class="wrap wrap--narrow">
        <div class="callout reveal">
            <span class="callout__ico"><x-site.icon name="alert" /></span>
            <div>
                <h3>Pilot certificates</h3>
                <p>VAT-MSA is in pilot, so the certificates it issues are pilot certificates. Production legal signatures require an approved signing profile and protected signing keys. A valid result here confirms the certificate exists in the platform's register, not that it carries legal force.</p>
            </div>
        </div>
    </div>
</section>

@include('site.partials.cta', ['title' => 'Issue certified invoices <em class="accent" style="color:var(--gold)">yourself.</em>', 'lead' => 'Apply for pilot access, or sign in if your organisation already has an account.'])

@endsection
