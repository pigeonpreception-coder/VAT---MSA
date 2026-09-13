<?php

namespace App\Http\Controllers\Operations;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\OperationsValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Operations\FixedAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ported from the source's own app/operations/{immovable-assets,movable-
 * assets}/page.tsx + components/FixedAssetManager.tsx -- Operations >
 * Immovable Asset Management and Movable Asset Management (NamRA e-VAT MS
 * master prompt section 16E). One controller serves both pages (indexImmovable/
 * indexMovable each render the same shared view, parameterised by asset
 * class), matching how App\Services\Operations\FixedAssetService and the
 * fixed_assets table are themselves shared rather than duplicated.
 */
class FixedAssetViewController extends Controller
{
    private const IMMOVABLE_CATEGORIES = ['LAND', 'BUILDING', 'OTHER'];
    private const MOVABLE_CATEGORIES = ['VEHICLE', 'EQUIPMENT', 'FURNITURE', 'IT_HARDWARE', 'OTHER'];

    public function __construct(private readonly FixedAssetService $assets) {}

    public function indexImmovable(Request $request): View
    {
        return $this->render($request, 'IMMOVABLE');
    }

    public function indexMovable(Request $request): View
    {
        return $this->render($request, 'MOVABLE');
    }

    private function render(Request $request, string $assetClass): View
    {
        $this->authorize('permission', 'fixed-assets:read');
        $user = $request->user();
        $assets = $this->assets->list($user, $assetClass, $request->query('organisation_id'));

        return view('operations.fixed-assets.index', [
            'assetClass' => $assetClass,
            'routeName' => $assetClass === 'IMMOVABLE' ? 'operations.immovable-assets' : 'operations.movable-assets',
            'title' => $assetClass === 'IMMOVABLE' ? 'Immovable Asset Management' : 'Movable Asset Management',
            'serialLabel' => $assetClass === 'IMMOVABLE' ? 'Title deed / registration number' : 'Serial number / registration',
            'categories' => $assetClass === 'IMMOVABLE' ? self::IMMOVABLE_CATEGORIES : self::MOVABLE_CATEGORIES,
            'assets' => $assets,
            'canManage' => $user->hasAppPermission('fixed-assets:manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');
        $returnTo = $this->returnRoute($request);
        $payload = [
            'schema_version' => '1.0.0', 'asset_class' => $request->input('asset_class'), 'asset_code' => $request->input('asset_code'),
            'category' => $request->input('category'), 'description' => $request->input('description'),
            'serial_or_registration_number' => $request->input('serial_or_registration_number') ?: null,
            'location_or_address' => $request->input('location_or_address'),
            'custodian_employee_id' => $request->input('custodian_employee_id') ?: null,
            'acquisition_date' => $request->input('acquisition_date'),
            'acquisition_cost_cents' => (int) $request->input('acquisition_cost_cents', 0),
            'current_value_cents' => $request->input('current_value_cents') !== null && $request->input('current_value_cents') !== ''
                ? (int) $request->input('current_value_cents') : null,
        ];

        try {
            $this->assets->register($payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid(), null);
        } catch (OperationsValidationException $e) {
            return redirect()->route($returnTo)->withErrors(collect($e->errors())->pluck('message', 'path')->all())->withInput();
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route($returnTo)->withErrors(['asset' => $e->getMessage()])->withInput();
        }

        return redirect()->route($returnTo)->with('status', 'Asset registered.');
    }

    public function valuation(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');
        $payload = ['schema_version' => '1.0.0', 'current_value_cents' => (int) $request->input('current_value_cents', 0)];

        return $this->runTransition($request, fn () => $this->assets->recordValuation($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid()), 'Valuation recorded.');
    }

    public function maintenance(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');

        return $this->runTransition($request, fn () => $this->assets->flagMaintenance($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid()), 'Asset flagged for maintenance.');
    }

    public function restoration(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');

        return $this->runTransition($request, fn () => $this->assets->restore($id, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid()), 'Asset restored to active service.');
    }

    public function disposal(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');
        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        return $this->runTransition($request, fn () => $this->assets->dispose($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid()), 'Asset disposed.');
    }

    private function runTransition(Request $request, \Closure $action, string $successMessage): RedirectResponse
    {
        $returnTo = $this->returnRoute($request);
        try {
            $action();
        } catch (OperationsValidationException $e) {
            return redirect()->route($returnTo)->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return redirect()->route($returnTo)->withErrors(['asset' => $e->getMessage()]);
        }

        return redirect()->route($returnTo)->with('status', $successMessage);
    }

    private function returnRoute(Request $request): string
    {
        $returnTo = (string) $request->input('return_to');

        return in_array($returnTo, ['operations.immovable-assets', 'operations.movable-assets'], true) ? $returnTo : 'operations.immovable-assets';
    }
}
