<?php

namespace App\Http\Controllers\Operations;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\OperationsValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Operations\FixedAssetService;
use App\Services\Operations\LogisticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ported from the source's own app/operations/logistics/page.tsx +
 * components/LogisticsManager.tsx -- Operations > Logistics Module (NamRA
 * e-VAT MS master prompt section 16E). A delivery always references the
 * tax invoice it is fulfilling; it never invents its own separate sale
 * record.
 */
class LogisticsViewController extends Controller
{
    public function __construct(
        private readonly LogisticsService $logistics,
        private readonly FixedAssetService $assets,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'logistics:read');
        $user = $request->user();
        $deliveries = $this->logistics->list($user, $request->query('organisation_id'));
        $vehicles = $user->hasAppPermission('fixed-assets:read') ? $this->assets->list($user, 'MOVABLE', $request->query('organisation_id')) : [];

        return view('operations.logistics.index', [
            'deliveries' => $deliveries,
            'vehicles' => $vehicles,
            'canManage' => $user->hasAppPermission('logistics:manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'logistics:manage');
        $payload = [
            'schema_version' => '1.0.0', 'delivery_number' => $request->input('delivery_number'),
            'reference_type' => $request->input('reference_type'), 'reference_id' => $request->input('reference_id') ?: null,
            'origin' => $request->input('origin'), 'destination' => $request->input('destination'),
            'vehicle_asset_id' => $request->input('vehicle_asset_id') ?: null, 'notes' => $request->input('notes') ?: null,
        ];

        try {
            $this->logistics->create($payload, $request->user(), (string) Str::uuid(), (string) Str::uuid(), null);
        } catch (OperationsValidationException $e) {
            return redirect()->route('operations.logistics')->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('operations.logistics')->withErrors(['delivery' => $e->getMessage()])->withInput();
        }

        return redirect()->route('operations.logistics')->with('status', 'Delivery created.');
    }

    public function dispatch(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'logistics:manage');

        return $this->runTransition(fn () => $this->logistics->dispatch($id, $request->user(), (string) Str::uuid(), (string) Str::uuid()), 'Delivery dispatched.');
    }

    public function deliver(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'logistics:manage');

        return $this->runTransition(fn () => $this->logistics->deliver($id, $request->user(), (string) Str::uuid(), (string) Str::uuid()), 'Delivery marked as delivered.');
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'logistics:manage');
        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        return $this->runTransition(fn () => $this->logistics->cancel($id, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid()), 'Delivery cancelled.');
    }

    private function runTransition(\Closure $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (OperationsValidationException $e) {
            return redirect()->route('operations.logistics')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route('operations.logistics')->withErrors(['delivery' => $e->getMessage()]);
        }

        return redirect()->route('operations.logistics')->with('status', $successMessage);
    }
}
