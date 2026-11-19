@props(['name', 'id' => null, 'value' => null, 'required' => false, 'min' => '0', 'size' => null, 'currency' => null])

{{-- Amount entry in currency units ("100.00") for forms whose controllers
     take integer cents. The visible field has no name; resources/js/app.js
     mirrors it into the hidden `$name` field as cents. Without JavaScript
     the hidden field stays empty, so a required amount fails validation
     rather than being saved 100x too small. --}}
@php
    $id ??= $name;
    $cents = old($name, $value);
    $display = ($cents === null || $cents === '') ? '' : number_format(((int) $cents) / 100, 2, '.', '');
    $symbol = $currency ?? \App\Support\Tenancy\TenantBranding::forUser(auth()->user())['currencySymbol'];
@endphp
<div class="input-group{{ $size === 'sm' ? ' input-group-sm' : '' }}">
    <span class="input-group-text">{{ $symbol }}</span>
    <input type="number" id="{{ $id }}" step="0.01" min="{{ $min }}" inputmode="decimal" value="{{ $display }}"
        data-cents-target="{{ $id }}__cents" @required($required)
        {{ $attributes->merge(['class' => 'form-control'.($errors->has($name) ? ' is-invalid' : '')]) }}>
    <input type="hidden" name="{{ $name }}" id="{{ $id }}__cents" value="{{ $cents }}">
</div>
