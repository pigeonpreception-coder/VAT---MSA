{{-- Closing call to action shared by the inner pages. $title may contain the
     <em class="accent"> emphasis markup and is always a literal from the calling
     view, never user input, which is why it is echoed unescaped. --}}
<section class="cta" aria-labelledby="cta-title">
    <div class="wrap">
        <span class="eyebrow" style="color:var(--gold)">Get started</span>
        <h2 id="cta-title" style="margin-top:20px">{!! $title !!}</h2>
        <p class="lead">{{ $lead }}</p>
        <div class="cta__actions">
            <a class="btn btn--gold btn--lg" href="{{ route('signup.create') }}">Request access <x-site.icon name="arrow-right" :size="18" /></a>
            <a class="btn btn--ghost-inverse btn--lg" href="{{ route('login') }}">Sign in</a>
        </div>
    </div>
    <div class="sunset" aria-hidden="true"></div>
    @include('site.partials.dunes')
</section>
