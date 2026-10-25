@extends('site.layout')

@section('title', 'Plans and access — VAT-MSA')
@section('description', 'VAT-MSA is offered through a controlled pilot. See what the Professional Pilot plan includes and how an application is verified before any licence is activated.')

@section('content')

<section class="pagehero" aria-labelledby="plans-title">
    <div class="wrap">
        <span class="eyebrow">Plans and access</span>
        <h1 id="plans-title">A controlled pilot, <em class="accent">by application.</em></h1>
        <p class="lead">Filling in the form activates nothing. Administrators verify your application and your organisation first, and only then is a licence switched on.</p>
    </div>
</section>

<section class="section--tight" aria-labelledby="plans-how">
    <div class="wrap access">
        <div class="reveal">
            <span class="eyebrow">How access works</span>
            <h2 class="h3" id="plans-how" style="margin-top:18px">Three steps, no surprises.</h2>
            <ol class="process">
                <li><b>Apply</b><span>Complete the access request with your organisation's details and confirm that you have the authority to apply on its behalf.</span></li>
                <li><b>Get verified</b><span>The application is held while administrators verify it and your organisation. No account, payment, subscription or licence is activated in the meantime.</span></li>
                <li><b>Go live</b><span>Once approved, your licence is activated and your team is invited in, each person with a role and, where needed, an approval path.</span></li>
            </ol>
        </div>

        <div class="reveal" style="--d:120ms">
            @include('site.partials.plan-card', ['plans' => $plans, 'compact' => false])
        </div>
    </div>
</section>

<section class="section section--sand" aria-labelledby="plans-before">
    <div class="wrap">
        <div class="section-head reveal">
            <span class="eyebrow">Before you apply</span>
            <h2 class="h2" id="plans-before">Have these <em class="accent">to hand.</em></h2>
        </div>
        <div class="cards">
            <article class="card reveal">
                <span class="card__ico"><x-site.icon name="file-text" /></span>
                <h3>What the form asks for</h3>
                <ul>
                    <li>Your VAT number and TIN</li>
                    <li>Legal name, trading name (optional) and address</li>
                    <li>Taxpayer type and how often you file</li>
                    <li>Company registration number, if you have one</li>
                </ul>
            </article>
            <article class="card reveal" style="--d:90ms">
                <span class="card__ico"><x-site.icon name="shield-check" /></span>
                <h3>Who may apply</h3>
                <p>An owner, director, partner, trustee or authorised representative, who confirms they are the verified company system administrator for the organisation and accepts the current terms and privacy notice.</p>
            </article>
            <article class="card reveal" style="--d:180ms">
                <span class="card__ico"><x-site.icon name="users" /></span>
                <h3>Already part of the pilot?</h3>
                <p>Sign in to your organisation's workspace. If you have forgotten your password, you can reset it from the sign-in page.</p>
                <p style="margin-top:16px"><a class="link-arrow" href="{{ route('login') }}">Sign in <x-site.icon name="arrow-right" :size="15" /></a></p>
            </article>
        </div>
    </div>
</section>

<section class="section" id="faq" aria-labelledby="plans-faq">
    <div class="wrap">
        <div class="section-head section-head--center reveal">
            <span class="eyebrow">Questions</span>
            <h2 class="h2" id="plans-faq">Straight answers.</h2>
        </div>
        <div class="faq reveal">
            @include('site.partials.faq')
        </div>
    </div>
</section>

@include('site.partials.cta', ['title' => 'Ready to <em class="accent" style="color:var(--gold)">apply?</em>', 'lead' => 'It takes a few minutes, and nothing is activated until you are verified.'])

@endsection
