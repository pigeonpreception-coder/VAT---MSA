<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Business\InventoryService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/{products,warehouses,inventory/{movements,transfers,availability,valuation}}/route.ts (Module 5 Phase D). */
class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory, private readonly OrganisationResolver $organisations) {}

    public function indexProducts(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));
        $products = Product::where('organisation_id', $organisation->id)->orderBy('name')->limit(200)->get();

        return response()->json(['organisation_id' => $organisation->id, 'products' => $products]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:manage');
        $correlationId = (string) Str::uuid();
        $product = $this->inventory->createProduct((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $product], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function indexWarehouses(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));
        $warehouses = Warehouse::where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->orderBy('name')->get();

        return response()->json(['organisation_id' => $organisation->id, 'warehouses' => $warehouses]);
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:manage');
        $correlationId = (string) Str::uuid();
        $warehouse = $this->inventory->createWarehouse((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $warehouse], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function indexMovements(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));
        $balances = InventoryBalance::where('organisation_id', $organisation->id)->orderBy('updated_at', 'desc')->limit(200)->get();

        return response()->json(['organisation_id' => $organisation->id, 'balances' => $balances]);
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:manage');
        $correlationId = (string) Str::uuid();
        $movement = $this->inventory->recordMovement((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $movement], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:manage');
        $correlationId = (string) Str::uuid();
        $transfer = $this->inventory->transferStock((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $transfer], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function availability(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:read');

        return response()->json($this->inventory->availability($request->user(), $request->query('organisation_id'), $request->query()));
    }

    public function valuation(Request $request): JsonResponse
    {
        $this->authorize('permission', 'inventory:read');

        return response()->json($this->inventory->valuation($request->user(), $request->query('organisation_id'), $request->query()));
    }
}
