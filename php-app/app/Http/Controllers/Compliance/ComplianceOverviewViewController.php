<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Compliance\ComplianceSnapshotService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Real Blade UI for ComplianceSnapshotService, alongside the JSON API
 * surface ComplianceSnapshotController already exposes -- see
 * InvoiceViewController's own doc comment for why this app keeps a
 * dedicated Blade-rendering controller next to each JSON one.
 *
 * Purely read-only: ComplianceSnapshotService has no write side at all
 * (getSnapshot() is its only method), so unlike every other slice in this
 * build-out there is no form, no route beyond one GET, and no permission
 * gate finer than the single compliance:read check already used
 * everywhere else in this module.
 *
 * Six of the snapshot's eleven fields already have their own dedicated,
 * fuller pages elsewhere in this build-out (obligations, cases, findings,
 * disputes, risks, refunds/refundTransitions) -- this page deliberately
 * does NOT re-render those as full tables a second time; it shows a
 * count and a link to the real page instead, the same "don't duplicate
 * a table that already has a home" reasoning the Organisations index
 * page's own snapshot cards already established. The four fields with no
 * page anywhere else (communications, notifications, consent_grants,
 * delegations) get real tables here, since this is their only UI. Two of
 * those four (consent_grants, delegations) have no Eloquent model and no
 * writer command anywhere in this migration -- confirmed by each table's
 * own migration doc comment (a full-repo grep of the TypeScript source
 * found no GrantConsent/CreateDelegation command, only demo seed data) --
 * so read-only is the correct, complete UI for them, not a gap.
 *
 * Actor names for communications/consent_grants/delegations are resolved
 * via one bulk User::whereIn() lookup, the same precedent Audit Cases
 * established, rather than adding relations to raw DB::table() reads that
 * intentionally have no Eloquent model backing them.
 *
 * Two of the five stat-card links (obligations.index, disputes.index) do
 * not exist on `main` at the time this slice was written -- they ship on
 * their own separate, independently-mergeable PRs alongside this one (see
 * docs/MIGRATION_MATRIX.md's own note on this build-out's fresh-smaller-PR
 * practice). The view guards each link with Route::has() rather than
 * assuming merge order, so this page never 500s regardless of which PR
 * lands first; each card activates as a real link the moment its own
 * route exists.
 */
class ComplianceOverviewViewController extends Controller
{
    public function __construct(private readonly ComplianceSnapshotService $snapshot) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();

        $snapshot = $this->snapshot->getSnapshot($actor);

        $userIds = collect()
            ->merge(collect($snapshot['communications'])->pluck('actor_id'))
            ->merge(collect($snapshot['consents'])->pluck('granted_by'))
            ->merge(collect($snapshot['delegations'])->pluck('delegator_user_id'))
            ->merge(collect($snapshot['delegations'])->pluck('delegate_user_id'))
            ->filter()->unique();
        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');

        return view('compliance.overview', [
            'snapshot' => $snapshot,
            'userNames' => $userNames,
            'counts' => [
                'obligations' => count($snapshot['obligations']),
                'cases' => count($snapshot['cases']),
                'disputes' => count($snapshot['disputes']),
                'risks' => count($snapshot['risks']),
                'refunds' => count($snapshot['refunds']),
            ],
        ]);
    }
}
