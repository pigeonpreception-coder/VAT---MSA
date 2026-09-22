<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/fixed-assets/{route,[id]/{route,valuation,
 * maintenance,restoration,disposal}}/route.ts, via lib/api/fixed-asset.ts's
 * handleFixedAssetList/handleFixedAssetGet/handleFixedAssetCommand dispatch.
 * Reuses App\Services\Operations\FixedAssetService exactly as
 * FixedAssetViewController already does -- this closes the JSON-API half
 * of that same command set, which every other business module in this
 * port (business-parties, quotations, expenses, etc.) already ships
 * alongside its Blade UI. No rate-limit middleware here, matching every
 * other business-domain write route in routes/web.php (unlike identity/
 * audit/invoice, this class of command was never put behind one).
 */
class FixedAssetController extends Controller
{
    public function __construct(private readonly FixedAssetService $assets) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'fixed-assets:read');
        $assets = $this->assets->list($request->user(), $request->query('asset_class'), $request->query('organisation_id'));

        return response()->json(['resources' => $assets]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'fixed-assets:read');
        $asset = $this->assets->get($id, $request->user());

        return response()->json(['resource' => $asset]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');
        $correlationId = (string) Str::uuid();
        $asset = $this->assets->register((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $asset], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function valuation(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');
        $correlationId = (string) Str::uuid();
        $asset = $this->assets->recordValuation($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $asset], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function maintenance(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');
        $correlationId = (string) Str::uuid();
        $asset = $this->assets->flagMaintenance($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $asset], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function restoration(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');
        $correlationId = (string) Str::uuid();
        $asset = $this->assets->restore($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $asset], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function disposal(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'fixed-assets:manage');
        $correlationId = (string) Str::uuid();
        $asset = $this->assets->dispose($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $asset], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
