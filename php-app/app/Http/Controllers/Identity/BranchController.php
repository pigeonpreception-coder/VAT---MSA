<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\CreateBranchRequest;
use App\Http\Requests\Identity\UpdateBranchRequest;
use App\Services\Identity\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Ported from app/api/v1/organisations/[id]/branches/route.ts and its [branchId]/route.ts sibling. */
class BranchController extends Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(Request $request, string $organisation): JsonResponse
    {
        $this->authorize('permission', 'identity:read');
        return response()->json(['branches' => $this->branches->list($request->user(), $organisation)]);
    }

    public function store(CreateBranchRequest $request, string $organisation): JsonResponse
    {
        $this->authorize('permission', 'organisations:manage');
        $branch = $this->branches->create($request->user(), $organisation, $request->validated(), (string) Str::uuid());
        return response()->json(['branch' => $branch], 201);
    }

    public function update(UpdateBranchRequest $request, string $organisation, string $branch): JsonResponse
    {
        $this->authorize('permission', 'organisations:manage');
        $updated = $this->branches->update($request->user(), $organisation, $branch, $request->validated(), (string) Str::uuid());
        return response()->json(['branch' => $updated]);
    }
}
