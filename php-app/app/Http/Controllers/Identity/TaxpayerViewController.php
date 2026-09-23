<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\TaxpayerService;
use Illuminate\View\View;

/**
 * Ported from the source's own app/taxpayers/page.tsx -- the canonical
 * taxpayer registry. Purely read-only (confirmed by reading the source
 * page in full -- no write action anywhere on it), reusing
 * TaxpayerService::list() directly. See that method's own doc comment
 * for why the read is deliberately unscoped, matching the source's own
 * query exactly.
 */
class TaxpayerViewController extends Controller
{
    public function __construct(private readonly TaxpayerService $taxpayers) {}

    public function index(): View
    {
        $this->authorize('permission', 'taxpayers:read');

        return view('taxpayers.index', ['taxpayers' => $this->taxpayers->list()]);
    }
}
