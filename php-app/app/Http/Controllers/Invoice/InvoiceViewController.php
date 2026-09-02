<?php

namespace App\Http\Controllers\Invoice;

use App\Http\Controllers\Controller;
use App\Services\Invoice\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from the source's own app/invoices/page.tsx +
 * app/invoices/[id]/page.tsx -- renders a real Blade view, reusing the
 * exact same App\Services\Invoice\InvoiceService methods the JSON
 * App\Http\Controllers\Invoice\InvoiceController already serves at
 * /api/v1/invoices, not a second, parallel query path. Matches
 * App\Http\Controllers\DashboardController's own precedent of a
 * dedicated view route living alongside its JSON API sibling.
 */
class InvoiceViewController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'invoices:read');

        return view('invoices.index', [
            'invoices' => $this->invoices->list($request->user()),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'invoices:read');

        $invoice = $this->invoices->find($id, $request->user());
        abort_if(! $invoice, Response::HTTP_NOT_FOUND);

        return view('invoices.show', [
            'invoice' => $invoice,
            'justCertified' => $request->boolean('created'),
        ]);
    }
}
