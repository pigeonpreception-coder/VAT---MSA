{{-- The public "verify an invoice" box. A plain GET form: SiteController::verify()
     turns whatever is pasted into a redirect to /verify/{token}, so it works with
     no JavaScript at all. Expects $id; optionally $code (echoed back) and $error. --}}
<form class="lookup" action="{{ route('site.verify') }}" method="get">
    <label class="lookup__label" for="{{ $id }}">Verification code or link</label>
    <div class="lookup__row">
        <input class="lookup__input" id="{{ $id }}" name="code" type="text" value="{{ $code ?? '' }}"
               placeholder="Paste the code or link from the invoice" maxlength="300" required
               autocomplete="off" autocapitalize="off" spellcheck="false"
               @if (! empty($error)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        <button class="btn" type="submit">Verify <x-site.icon name="arrow-right" :size="16" /></button>
    </div>
    @if (! empty($error))
        <p class="lookup__error" id="{{ $id }}-error" role="alert">{{ $error }}</p>
    @endif
</form>
