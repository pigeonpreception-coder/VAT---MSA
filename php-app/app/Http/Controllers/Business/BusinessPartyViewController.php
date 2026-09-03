<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Business\BusinessPartyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ported from the source's own app/commercial/parties/page.tsx +
 * PartyManager.tsx -- the customer/supplier directory, the first screen in
 * this migration's frontend build-out with a genuine write form rather than
 * a read-only dashboard. Reuses App\Services\Business\BusinessPartyService
 * directly (the same create/update/deactivate/search methods
 * App\Http\Controllers\Business\BusinessPartyController already serves at
 * /api/v1/business-parties, not a second query or command path).
 *
 * Two deliberate simplifications from the source, both because the data
 * they need isn't produced by BusinessPartyService::search/present at all
 * (adding it would mean a second, wider query path for this view alone):
 *  - The source's "Trust" column/metric (synthetic-verification signal from
 *    a join against supplier_verification_snapshots) is omitted here.
 *  - The source's "Synthetic check" action (App\Services\Business\
 *    SupplierVerificationService::verify, already ported and reachable at
 *    POST /api/v1/business-parties/{id}/verification) has no UI on this
 *    screen yet -- tracked in docs/MIGRATION_MATRIX.md.
 *
 * Unlike the source's client-side fetch()-driven PartyManager, this is a
 * traditional server-rendered Blade form (POST + redirect), matching this
 * migration's Blade/session-driven precedent everywhere else. The source's
 * own /api/v1/business-parties/** routes have no step-up (password.confirm)
 * gate on writes, so neither does this view.
 */
class BusinessPartyViewController extends Controller
{
    public function __construct(private readonly BusinessPartyService $parties) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'parties:manage');

        $snapshot = $this->parties->search($request->user(), null, $request->query());
        $editing = null;
        if ($request->query('edit')) {
            $editing = collect($snapshot['parties'])->firstWhere('id', $request->query('edit'));
        }

        return view('parties.index', [
            'snapshot' => $snapshot,
            'editing' => $editing,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'parties:manage');

        try {
            $this->parties->create($this->payload($request), $request->user(), (string) Str::uuid(), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('parties.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('parties.index')->withErrors(['party' => $e->getMessage()])->withInput();
        }

        return redirect()->route('parties.index')->with('status', 'Business party created.');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'parties:manage');

        try {
            $this->parties->update($id, $this->payload($request), $request->user(), (string) Str::uuid(), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('parties.index', ['edit' => $id])->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('parties.index', ['edit' => $id])->withErrors(['party' => $e->getMessage()])->withInput();
        }

        return redirect()->route('parties.index')->with('status', 'Business party updated.');
    }

    public function deactivate(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'parties:manage');

        try {
            $this->parties->deactivate($id, ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')], $request->user(), (string) Str::uuid(), (string) Str::uuid(), null);
        } catch (BusinessValidationException $e) {
            return redirect()->route('parties.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('parties.index')->withErrors(['party' => $e->getMessage()]);
        }

        return redirect()->route('parties.index')->with('status', 'Business party deactivated.');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        return [
            'schema_version' => '1.0.0',
            'display_name' => $request->input('display_name'),
            'legal_name' => $request->input('legal_name'),
            'vat_number' => $request->input('vat_number'),
            'tin' => $request->input('tin'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'relationships' => (array) $request->input('relationships', []),
        ];
    }
}
