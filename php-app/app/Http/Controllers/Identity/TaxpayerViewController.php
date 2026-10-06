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
        $taxpayers = $this->taxpayers->list();

        // Blade view redesign (2026-10-06): the stat tiles and both
        // breakdowns below are derived from the same already-fetched
        // $taxpayers collection -- no new query, the same pattern already
        // used across this sweep's other registry screens (Access Rights,
        // Obligations).
        $metrics = [
            'total' => count($taxpayers),
            'active' => collect($taxpayers)->where('vat_status', 'ACTIVE')->count(),
            'suspended' => collect($taxpayers)->where('vat_status', 'SUSPENDED')->count(),
            'transactions' => (int) collect($taxpayers)->sum('transaction_count'),
        ];
        $byCapability = collect($taxpayers)
            ->flatMap(fn ($t) => array_filter(explode(',', $t['capabilities'] ?? '')))
            ->countBy()->sortDesc()
            ->map(fn ($count, $capability) => ['capability' => $capability, 'count' => $count])
            ->values()->all();
        $byFrequency = collect($taxpayers)->countBy('return_frequency')->sortDesc()
            ->map(fn ($count, $frequency) => ['frequency' => $frequency, 'count' => $count])
            ->values()->all();

        return view('taxpayers.index', compact('taxpayers', 'metrics', 'byCapability', 'byFrequency'));
    }
}
