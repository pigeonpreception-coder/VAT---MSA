<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Business\BusinessPartyService;
use App\Services\Business\SupplierVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Blade UI bundling BusinessPartyService (customers/suppliers: list,
 * create, deactivate) with SupplierVerificationService (verify a supplier
 * against the national taxpayer register, and its history), alongside the
 * JSON API surface BusinessPartyController already exposes -- see
 * InvoiceViewController's own doc comment for why this app keeps a
 * dedicated Blade-rendering controller next to each JSON one.
 *
 * Built as one slice deliberately: SupplierVerificationService (124 lines)
 * is the smallest standalone-sized candidate remaining at this point in the
 * build-out, but it has no read surface of its own beyond history() (which
 * needs a party to already exist) -- a "verify supplier" page with no way
 * to see or pick which party to verify would not be a usable screen.
 * BusinessPartyService::update() is the one write action left out: create
 * and deactivate plus verification cover the module's real workflow
 * (register a party, verify it, retire it), and update() is materially the
 * same form as create() with upsert-relationship semantics that would add
 * complexity without a correspondingly strong need for this slice.
 *
 * `OfflineSyncService` (116 lines, technically smaller) was deliberately
 * passed over for this slot: its own doc comment is explicit that the
 * source never actually wired up real device-signature verification, so
 * every batch is written `status='REJECTED'` regardless of content -- there
 * is no read/list method at all (only `receive()`), and the payload itself
 * (device signatures, hash chains, sequence numbers) is a machine-to-machine
 * sync-client protocol, not a realistic browser form for a human to fill
 * in. No UI was built for it for the same reason no UI was built for a
 * decide action Disputes' backend can't perform: building one would imply
 * capability that does not exist.
 *
 * `parties:manage` gates every route here, read and write alike -- matching
 * `BusinessPartyController` exactly, which has no separate lighter read
 * permission either. Held broadly by business-facing roles (PILOT_ADMIN,
 * taxpayer roles, seller/buyer portal roles), never by NamRA roles:
 * customers/suppliers are the taxpayer's own commercial data, not a
 * compliance concern.
 */
class BusinessPartyViewController extends Controller
{
    public function __construct(
        private readonly BusinessPartyService $parties,
        private readonly SupplierVerificationService $verification,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'parties:manage');
        $actor = $request->user();

        $result = $this->parties->search($actor, $request->query('organisation_id'), $request->only(['status', 'relationship', 'q']));

        return view('business-parties.index', [
            'parties' => $result['parties'], 'totalCount' => $result['total_count'],
            'filters' => $request->only(['status', 'relationship', 'q']),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'parties:manage');
        $actor = $request->user();

        try {
            // history()'s only throw is the party-not-found-in-scope case
            // (BusinessResourceException, 404) -- no other resource error
            // is possible here, unlike verify()'s own broader set below.
            $history = $this->verification->history($id, $actor, $request->query('organisation_id'));
        } catch (BusinessResourceException $e) {
            abort(Response::HTTP_NOT_FOUND, $e->getMessage());
        }

        return view('business-parties.show', ['party' => $history['party'], 'snapshots' => $history['snapshots']]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'parties:manage');

        $payload = [
            'schema_version' => '1.0.0', 'display_name' => (string) $request->input('display_name'),
            'legal_name' => $request->input('legal_name') ?: null, 'vat_number' => $request->input('vat_number') ?: null,
            'tin' => $request->input('tin') ?: null, 'email' => $request->input('email') ?: null,
            'phone' => $request->input('phone') ?: null, 'address' => $request->input('address') ?: null,
            'relationships' => array_filter((array) $request->input('relationships', [])),
        ];

        try {
            $party = $this->parties->create($payload, $request->user(), (string) Str::uuid(), (string) Str::uuid(), $request->input('organisation_id'));
        } catch (BusinessValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (RepositoryConflictException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('business-parties.show', $party['id'])->with('status', 'Party created.');
    }

    public function storeVerification(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'parties:manage');

        try {
            $this->verification->verify($id, $request->user(), (string) Str::uuid(), (string) Str::uuid(), $request->query('organisation_id'));
        } catch (BusinessResourceException $e) {
            // Redirects explicitly to the show page rather than back(): the
            // verify button only ever lives there, and back() depends on
            // Laravel's session-tracked previous URL actually being set --
            // an assumption already proven fragile once in this build-out
            // (see ConfirmPasswordController's own fix, same session).
            return redirect()->route('business-parties.show', $id)->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('business-parties.show', $id)->with('status', 'Supplier verified against the national taxpayer register.');
    }

    public function storeDeactivation(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'parties:manage');

        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        try {
            $this->parties->deactivate($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid(), $request->query('organisation_id'));
        } catch (BusinessValidationException $e) {
            return redirect()->route('business-parties.show', $id)->withErrors($this->fieldErrors($e))->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('business-parties.show', $id)->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('business-parties.show', $id)->with('status', 'Party deactivated.');
    }

    /** @return array<string, string> */
    private function fieldErrors(BusinessValidationException $e): array
    {
        return collect($e->errors())->mapWithKeys(fn (array $error) => [ltrim($error['path'], '/') ?: 'form' => $error['message']])->all();
    }
}
