<?php

namespace App\Http\Controllers\Document;

use App\Exceptions\PlatformResourceException;
use App\Http\Controllers\Controller;
use App\Services\Document\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/api/platform.ts's handleDocumentUpload/
 * handleDocumentScanResult -- the minimal Upload -> Quarantine ->
 * ScanDecision slice of Module 22 pulled forward as the real prerequisite
 * for closing Phase 11's `DOCUMENT`-sourced evidence citation gap.
 *
 * The source's own `enforceRateLimits` calls on both handlers are NOT
 * ported here -- a distinct, orthogonal concern (this migration's rate
 * limiting story is not yet decided anywhere else in the codebase either)
 * left for whichever phase takes that on directly, not silently bundled
 * into this one.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'documents:upload');

        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            throw new PlatformResourceException("Multipart field 'file' is required.");
        }

        $correlationId = (string) Str::uuid();
        $input = [
            'owner_domain' => (string) $request->input('owner_domain', ''),
            'owner_resource_id' => (string) $request->input('owner_resource_id', ''),
            'classification' => (string) $request->input('classification', 'TAX_CONFIDENTIAL'),
        ];
        $organisationId = $request->input('organisation_id') ?: null;

        $document = $this->documents->upload($file, $input, $request->user(), $organisationId, $correlationId);

        return response()->json([
            'document' => $document,
            'next_action' => 'External malware scanning must mark the object clean before it can become available.',
        ], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function scanResult(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'documents:manage');

        $correlationId = (string) Str::uuid();
        $document = $this->documents->completeScan($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['document' => $document], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
