@extends('layouts.app')

@section('title', 'Edit quotation')

@php
    $formatQuantity = fn (int $micros) => rtrim(rtrim(number_format($micros / 1_000_000, 6, '.', ''), '0'), '.') ?: '0';
@endphp

@section('content')
<div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <div class="text-uppercase text-muted small fw-semibold">Quotation lifecycle</div>
        <h1 class="h3 mb-1">Edit {{ $quotation['quotation_number'] }}</h1>
        <p class="text-muted mb-0">Only an unexpired issued quotation may change. Accepted, rejected, expired and converted quotations remain immutable.</p>
    </div>
    <a href="{{ route('quotations.index') }}" class="btn btn-secondary">Back to quotations</a>
</div>

<div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Status</div>
            <div class="fs-4 fw-semibold"><x-status-badge :value="$quotation['status']" type="status" /></div>
            <div class="small text-muted">Lifecycle guard enforced server-side</div>
        </div></div>
    </div>
    <div class="col">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Recorded revisions</div>
            <div class="fs-2 fw-semibold">{{ number_format($quotation['revision_count']) }}</div>
            <div class="small text-muted">Hash-chained immutable snapshots</div>
        </div></div>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Quotation needs attention.</strong>
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (! $editPolicy['allowed'])
    <div class="alert alert-danger" role="alert">
        <strong>This quotation cannot be edited.</strong>
        <div>{{ $editPolicy['reason'] }}</div>
    </div>
@else
    <div class="card">
        <div class="card-header">
            <div class="fw-semibold">Commercial terms</div>
            <div class="text-muted small">Every successful save appends a hashed immutable revision</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('quotations.update', $quotation['id']) }}">
                @csrf
                @method('PATCH')

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="quotation_number" class="form-label">Quotation number</label>
                        <input type="text" class="form-control font-monospace" id="quotation_number" name="quotation_number" value="{{ $quotation['quotation_number'] }}" readonly>
                        <div class="form-text">Immutable after first issue.</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="customer_party_id" class="form-label">Customer</label>
                        <select class="form-select" id="customer_party_id" name="customer_party_id" required>
                            @foreach ($customers as $party)
                                <option value="{{ $party['id'] }}" @selected(old('customer_party_id', $quotation['customer_party_id']) === $party['id'])>{{ $party['display_name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Currency</label>
                        <output class="form-control">N$</output>
                        <div class="form-text">Namibian-dollar presentation; ISO code remains internal.</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="issue_date" class="form-label">Issue date</label>
                        <input type="date" class="form-control" id="issue_date" name="issue_date" required value="{{ old('issue_date', $quotation['issue_date']) }}">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="valid_until" class="form-label">Valid until</label>
                        <input type="date" class="form-control" id="valid_until" name="valid_until" required value="{{ old('valid_until', $quotation['valid_until']) }}">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <input type="text" class="form-control" id="notes" name="notes" maxlength="2000" value="{{ old('notes', $quotation['notes']) }}">
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h2 class="h6 mb-0">Quotation lines</h2>
                        <div class="text-muted small">Amounts and VAT are recalculated server-side</div>
                    </div>
                    <button type="button" id="add-line" class="btn btn-sm btn-secondary">Add line</button>
                </div>

                <div id="quotation-lines">
                    @foreach ($quotation['lines'] as $i => $line)
                        <fieldset class="quotation-line border rounded p-3 mb-3" data-line>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <legend class="h6 mb-0">Line {{ $i + 1 }}</legend>
                                <button type="button" class="btn btn-sm btn-outline-danger remove-line" @disabled(count($quotation['lines']) === 1)>Remove line</button>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label">Catalog product</label>
                                    <select class="form-select" name="lines[{{ $i }}][product_id]">
                                        <option value="">Custom line</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}" @selected($line['product_id'] === $product->id)>{{ $product->sku }} — {{ $product->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label">Description</label>
                                    <input class="form-control" name="lines[{{ $i }}][description]" required maxlength="500" value="{{ $line['description'] }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" class="form-control" name="lines[{{ $i }}][quantity]" required min="0.000001" step="0.000001" value="{{ $formatQuantity($line['quantity_micros']) }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Unit</label>
                                    <input class="form-control" name="lines[{{ $i }}][unit_code]" required maxlength="12" value="{{ $line['unit_code'] }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Unit price (cents)</label>
                                    <input type="number" class="form-control" name="lines[{{ $i }}][unit_price_cents]" required min="0" step="1" value="{{ $line['unit_price_cents'] }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tax category</label>
                                    <select class="form-select line-tax-category" name="lines[{{ $i }}][tax_category]">
                                        <option value="STANDARD" @selected($line['tax_category'] === 'STANDARD')>Standard</option>
                                        <option value="ZERO_RATED" @selected($line['tax_category'] === 'ZERO_RATED')>Zero rated</option>
                                        <option value="EXEMPT" @selected($line['tax_category'] === 'EXEMPT')>Exempt</option>
                                        <option value="OUT_OF_SCOPE" @selected($line['tax_category'] === 'OUT_OF_SCOPE')>Out of scope</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tax rate (basis points)</label>
                                    <input type="number" class="form-control line-tax-rate" name="lines[{{ $i }}][tax_rate_bps]" required min="0" max="10000" step="1" value="{{ $line['tax_rate_bps'] }}" @if ($line['tax_category'] !== 'STANDARD') readonly @endif>
                                </div>
                            </div>
                        </fieldset>
                    @endforeach
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save quotation revision</button>
                    <a href="{{ route('quotations.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <template id="line-template">
        <fieldset class="quotation-line border rounded p-3 mb-3" data-line>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <legend class="h6 mb-0">Line</legend>
                <button type="button" class="btn btn-sm btn-outline-danger remove-line">Remove line</button>
            </div>
            <div class="row g-2">
                <div class="col-md-5">
                    <label class="form-label">Catalog product</label>
                    <select class="form-select" name="lines[__INDEX__][product_id]">
                        <option value="">Custom line</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-7">
                    <label class="form-label">Description</label>
                    <input class="form-control" name="lines[__INDEX__][description]" required maxlength="500">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" class="form-control" name="lines[__INDEX__][quantity]" required min="0.000001" step="0.000001" value="1">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit</label>
                    <input class="form-control" name="lines[__INDEX__][unit_code]" required maxlength="12" value="EA">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit price (cents)</label>
                    <input type="number" class="form-control" name="lines[__INDEX__][unit_price_cents]" required min="0" step="1" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tax category</label>
                    <select class="form-select line-tax-category" name="lines[__INDEX__][tax_category]">
                        <option value="STANDARD" selected>Standard</option>
                        <option value="ZERO_RATED">Zero rated</option>
                        <option value="EXEMPT">Exempt</option>
                        <option value="OUT_OF_SCOPE">Out of scope</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tax rate (basis points)</label>
                    <input type="number" class="form-control line-tax-rate" name="lines[__INDEX__][tax_rate_bps]" required min="0" max="10000" step="1" value="1500">
                </div>
            </div>
        </fieldset>
    </template>

    <script>
        (function () {
            var container = document.getElementById('quotation-lines');
            var template = document.getElementById('line-template');
            var addButton = document.getElementById('add-line');
            var nextIndex = {{ count($quotation['lines']) }};

            function renumber() {
                var fieldsets = container.querySelectorAll('[data-line]');
                fieldsets.forEach(function (fieldset, index) {
                    var legend = fieldset.querySelector('legend');
                    if (legend) legend.textContent = 'Line ' + (index + 1);
                    var removeButton = fieldset.querySelector('.remove-line');
                    if (removeButton) removeButton.disabled = fieldsets.length === 1;
                });
            }

            addButton.addEventListener('click', function () {
                var clone = template.content.cloneNode(true);
                clone.querySelectorAll('[name]').forEach(function (field) {
                    field.name = field.name.replace('__INDEX__', String(nextIndex));
                });
                nextIndex += 1;
                container.appendChild(clone);
                renumber();
            });

            container.addEventListener('click', function (event) {
                if (!event.target.classList.contains('remove-line')) return;
                var fieldsets = container.querySelectorAll('[data-line]');
                if (fieldsets.length <= 1) return;
                event.target.closest('[data-line]').remove();
                renumber();
            });

            container.addEventListener('change', function (event) {
                if (!event.target.classList.contains('line-tax-category')) return;
                var fieldset = event.target.closest('[data-line]');
                var rateInput = fieldset.querySelector('.line-tax-rate');
                if (event.target.value === 'STANDARD') {
                    rateInput.readOnly = false;
                    if (rateInput.value === '0') rateInput.value = '1500';
                } else {
                    rateInput.readOnly = true;
                    rateInput.value = '0';
                }
            });

            renumber();
        })();
    </script>
@endif
@endsection
