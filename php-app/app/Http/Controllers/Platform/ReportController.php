<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\ReportExportService;
use App\Support\Access\StepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as FileResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/api/platform.ts's report-run/report-export handlers
 * (Module 7 Phases A-C) -- kept 1:1 with the source's
 * app/api/v1/reports/** route shapes. `requestExport`/`approveExport`
 * are the two commands with a data-conditional (not route-wide) step-up
 * requirement -- see App\Support\Access\StepUp's own doc comment.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportExportService $reports) {}

    public function run(Request $request, string $code): JsonResponse
    {
        $this->authorize('permission', 'reports:run');
        $result = $this->reports->runInline($code, (array) $request->json()->all(), $request->user());

        return response()->json(['report_run' => $result], Response::HTTP_CREATED);
    }

    public function publish(Request $request, string $reportRunId): JsonResponse
    {
        $this->authorize('permission', 'reports:run');
        $correlationId = (string) Str::uuid();
        $result = $this->reports->publish($reportRunId, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['report_run' => $result]);
    }

    public function requestExport(Request $request, string $reportRunId): JsonResponse
    {
        $this->authorize('permission', 'reports:run');
        $correlationId = (string) Str::uuid();
        $result = $this->reports->requestExport($reportRunId, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, StepUp::isFresh($request));

        return response()->json(['report_export' => $result], Response::HTTP_CREATED);
    }

    public function approveExport(Request $request, string $exportId): JsonResponse
    {
        $this->authorize('permission', 'reports:run');
        $correlationId = (string) Str::uuid();
        $result = $this->reports->approveExport($exportId, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, StepUp::isFresh($request));

        return response()->json(['report_export' => $result]);
    }

    public function cancelExport(Request $request, string $exportId): JsonResponse
    {
        $this->authorize('permission', 'reports:run');
        $correlationId = (string) Str::uuid();
        $result = $this->reports->cancelExport($exportId, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['report_export' => $result]);
    }

    public function showExport(Request $request, string $exportId): JsonResponse
    {
        $this->authorize('permission', 'reports:read');

        return response()->json(['report_export' => $this->reports->getExport($exportId, $request->user())]);
    }

    public function downloadExport(Request $request, string $exportId): FileResponse
    {
        $this->authorize('permission', 'reports:read');
        $correlationId = (string) Str::uuid();
        $result = $this->reports->downloadExport($exportId, $request->user(), $correlationId);
        $safeFileName = str_replace('"', '', $result['fileName']);

        return response($result['bytes'], Response::HTTP_OK, [
            'Content-Type' => $result['contentType'],
            'Content-Disposition' => "attachment; filename=\"{$safeFileName}\"",
            'x-correlation-id' => $correlationId,
            'Cache-Control' => 'no-store',
        ]);
    }
}
