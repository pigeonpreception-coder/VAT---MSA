@extends('layouts.app')

@section('title', 'Inventory (POS)')

@section('content')
<div class="mb-4">
    <div class="text-uppercase text-muted small fw-semibold">Operations</div>
    <h1 class="h3 mb-1">Inventory Module</h1>
    <p class="text-muted mb-0">Functions like a point-of-sale system: build a cart, complete the sale, and a certified tax invoice plus matching stock issue are recorded together.</p>
</div>

@if (session('warning'))
    <div class="alert alert-warning" role="alert">{{ session('warning') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>The sale could not be completed.</strong>
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (! $canSell)
    <div class="alert alert-info">You have read-only access to inventory. A user with <code>inventory:manage</code> can complete a sale.</div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Products</div>
                <div class="text-muted small">Available stock across warehouses</div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle" id="pos-product-table">
                    <caption class="visually-hidden">Products with SKU, unit price and available action to add to cart</caption>
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Price</th>
                            @if ($canSell)
                                <th scope="col"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            <tr>
                                <td>
                                    <strong>{{ $product->name }}</strong>
                                    <div class="text-muted small font-monospace">{{ $product->sku }}</div>
                                </td>
                                <td>NAD {{ number_format($product->sales_price_cents / 100, 2) }}</td>
                                @if ($canSell)
                                    <td>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="posAddToCart('{{ $product->id }}', {{ Illuminate\Support\Js::from($product->name) }}, {{ (int) $product->sales_price_cents }}, {{ (int) $product->tax_rate_bps }})">Add</button>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canSell ? 3 : 2 }}" class="text-center text-muted py-4">No products are registered.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <div class="fw-semibold">Cart</div>
                <div class="text-muted small">Point-of-sale checkout</div>
            </div>
            <div class="card-body">
                @if ($canSell)
                    <form method="POST" action="{{ route('operations.inventory.checkout') }}" id="pos-form">
                        @csrf
                        <div class="mb-3">
                            <label for="warehouse_id" class="form-label">Warehouse</label>
                            <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm mb-0" id="pos-cart-table">
                                <thead><tr><th>Item</th><th>Qty</th><th>Line total</th><th></th></tr></thead>
                                <tbody id="pos-cart-body">
                                    <tr id="pos-cart-empty"><td colspan="4" class="text-center text-muted py-3">The cart is empty.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mb-3">
                            <label for="customer_vat_number" class="form-label">Buyer VAT number (optional)</label>
                            <input type="text" class="form-control font-monospace" id="customer_vat_number" name="customer_vat_number" placeholder="Leave blank for a walk-in consumer" list="pos-taxpayers">
                            <datalist id="pos-taxpayers">
                                @foreach ($taxpayers as $taxpayer)
                                    <option value="{{ $taxpayer->vat_number }}">{{ $taxpayer->legal_name }}</option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="border rounded p-2 mb-3 small">
                            <div class="d-flex justify-content-between"><span>Tax-exclusive value</span><strong id="pos-net">NAD 0.00</strong></div>
                            <div class="d-flex justify-content-between"><span>VAT</span><strong id="pos-tax">NAD 0.00</strong></div>
                            <div class="d-flex justify-content-between fs-6"><span>Payable amount</span><strong id="pos-total">NAD 0.00</strong></div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" id="pos-submit" disabled>Complete sale</button>
                    </form>
                @else
                    <p class="text-muted mb-0">You do not have permission to complete a sale.</p>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($canSell)
<script>
    var posCart = [];

    function posFormatMoney(cents) {
        return 'NAD ' + (cents / 100).toFixed(2);
    }

    function posAddToCart(productId, name, priceCents, taxRateBps) {
        var existing = posCart.find(function (line) { return line.productId === productId; });
        if (existing) { existing.quantity += 1; } else { posCart.push({ productId: productId, name: name, priceCents: priceCents, taxRateBps: taxRateBps, quantity: 1 }); }
        posRender();
    }

    function posUpdateQuantity(productId, quantity) {
        quantity = parseInt(quantity, 10);
        posCart = posCart.map(function (line) { return line.productId === productId ? Object.assign({}, line, { quantity: quantity }) : line; }).filter(function (line) { return line.quantity > 0; });
        posRender();
    }

    function posRemoveLine(productId) {
        posCart = posCart.filter(function (line) { return line.productId !== productId; });
        posRender();
    }

    function posRender() {
        var body = document.getElementById('pos-cart-body');
        body.innerHTML = '';
        var netTotal = 0, taxTotal = 0;
        if (posCart.length === 0) {
            body.innerHTML = '<tr id="pos-cart-empty"><td colspan="4" class="text-center text-muted py-3">The cart is empty.</td></tr>';
        }
        posCart.forEach(function (line) {
            var net = line.priceCents * line.quantity;
            var tax = Math.round(net * line.taxRateBps / 10000);
            netTotal += net; taxTotal += tax;
            var row = document.createElement('tr');
            row.innerHTML = '<td>' + line.name + '<input type="hidden" name="product_id[]" value="' + line.productId + '"></td>'
                + '<td><input type="number" class="form-control form-control-sm" style="width:70px" min="1" value="' + line.quantity + '" name="quantity[]" onchange="posUpdateQuantity(\'' + line.productId + '\', this.value)"></td>'
                + '<td>' + posFormatMoney(net + tax) + '</td>'
                + '<td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="posRemoveLine(\'' + line.productId + '\')">&times;</button></td>';
            body.appendChild(row);
        });
        document.getElementById('pos-net').textContent = posFormatMoney(netTotal);
        document.getElementById('pos-tax').textContent = posFormatMoney(taxTotal);
        document.getElementById('pos-total').textContent = posFormatMoney(netTotal + taxTotal);
        document.getElementById('pos-submit').disabled = posCart.length === 0;
    }
</script>
@endif
@endsection
