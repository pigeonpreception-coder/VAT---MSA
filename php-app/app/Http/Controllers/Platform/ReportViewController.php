<?php

namespace App\Http\Controllers\Platform;

use App\Exceptions\PlatformResourceException;
use App\Exceptions\PlatformValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Platform\DataProductService;
use App\Services\Platform\ReportExportService;
use App\Support\Access\StepUp;
use App\Support\Access\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ports the source's own reports/analytics screens onto
 * App\Services\Platform\ReportExportService (all 7 methods) and
 * App\Services\Platform\DataProductService (all 5 methods) directly --
 * this view adds no query of its own for anything either service already
 * exposes. The catalogue read (`report_definitions`) and the "my
 * runs"/"my exports"/"pending approvals"/"publishable model runs" lists
 * are direct supporting reads with no command precedent to reuse, the
 * same "no second query path for business logic, a listing read is fine"
 * posture Document/Inventory's own view controllers already established.
 *
 * **Data-conditional step-up, not route-wide**: unlike every other
 * password.confirm-gated Blade route in this migration,
 * requestExport/approveExport only need a fresh step-up when the report's
 * own classification/export flag is sensitive -- data the router cannot
 * see (see App\Support\Access\StepUp's own doc comment). Gating the whole
 * route would over-restrict the non-sensitive case the source itself
 * exempts. Instead: pass StepUp::isFresh($request) through, and if the
 * service still refuses for exactly that reason, redirect to the real
 * password.confirm screen with the reports page as the intended
 * destination, then ask the actor to retry the action -- this migration's
 * Blade forms are plain POSTs with no client-side replay, so a manual
 * retry (now with a satisfied freshness window) is the honest UX, not a
 * silently-swallowed failure.
 */
class ReportViewController extends Controller
{
    public function __construct(
        private readonly ReportExportService $reports,
        private readonly DataProductService $dataProducts,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'reports:read');
        $user = $request->user();
        $isNational = TenantScope::isNational($user);

        $definitions = DB::table('report_definitions')->where('status', 'ACTIVE')->orderBy('code')->get();

        $myRuns = DB::table('report_runs as r')
            ->join('report_definitions as d', 'd.id', '=', 'r.report_definition_id')
            ->where('r.requested_by', $user->id)->orderByDesc('r.requested_at')->limit(50)
            ->select('r.id', 'r.status', 'r.row_count', 'r.requested_at', 'd.code', 'd.name')
            ->get();

        $myExports = DB::table('report_exports as x')
            ->join('report_runs as r', 'r.id', '=', 'x.report_run_id')
            ->join('report_definitions as d', 'd.id', '=', 'r.report_definition_id')
            ->where('x.requested_by', $user->id)->orderByDesc('x.requested_at')->limit(50)
            ->select('x.id', 'x.status', 'x.requested_at', 'x.expires_at', 'x.requires_step_up', 'd.code as report_code')
            ->get();

        $pendingApprovals = $isNational
            ? DB::table('report_exports as x')
                ->join('report_runs as r', 'r.id', '=', 'x.report_run_id')
                ->join('report_definitions as d', 'd.id', '=', 'r.report_definition_id')
                ->where('x.status', 'PENDING_APPROVAL')->where('x.requested_by', '<>', $user->id)
                ->orderBy('x.requested_at')->limit(50)
                ->select('x.id', 'x.requested_at', 'x.requires_step_up', 'd.code as report_code')
                ->get()
            : collect();

        $dataProducts = $this->dataProducts->list();
        $metrics = $this->dataProducts->approvedMetrics(null, null);
        $anomalies = $this->dataProducts->anomalyCandidates(null);

        $publishedRuns = $isNational
            ? DB::table('report_runs as r')->join('report_definitions as d', 'd.id', '=', 'r.report_definition_id')
                ->where('r.status', 'PUBLISHED')->orderByDesc('r.requested_at')->limit(50)
                ->select('r.id', 'd.code')->get()
            : collect();

        $publishableModelRuns = $isNational
            ? DB::table('analytics_model_runs as m')
                ->leftJoin('data_product_snapshots as s', 's.model_run_id', '=', 'm.id')
                ->join('data_products as dp', 'dp.id', '=', 'm.data_product_id')
                ->whereNull('s.id')->where('m.status', 'COMPLETED')
                ->orderByDesc('m.requested_at')->limit(50)
                ->select('m.id', 'dp.code as data_product_code', 'm.requested_at')->get()
            : collect();

        return view('reports.index', [
            'definitions' => $definitions, 'myRuns' => $myRuns, 'myExports' => $myExports,
            'pendingApprovals' => $pendingApprovals, 'dataProducts' => $dataProducts, 'metrics' => $metrics,
            'anomalies' => $anomalies, 'publishedRuns' => $publishedRuns, 'publishableModelRuns' => $publishableModelRuns,
            'isNational' => $isNational, 'canRun' => $user->hasAppPermission('reports:run'),
        ]);
    }

    public function run(Request $request, string $code): RedirectResponse
    {
        $this->authorize('permission', 'reports:run');
        $parameters = array_filter(['case_id' => $request->input('case_id')], fn ($v) => $v !== null && $v !== '');

        try {
            $this->reports->runInline($code, $parameters, $request->user());
        } catch (PlatformValidationException $e) {
            return redirect()->route('reports.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('reports.index')->withErrors(['run' => $e->getMessage()]);
        }

        return redirect()->route('reports.index')->with('status', "Report {$code} run inline.");
    }

    public function publish(Request $request, string $reportRunId): RedirectResponse
    {
        $this->authorize('permission', 'reports:run');

        try {
            $this->reports->publish($reportRunId, ['schema_version' => '1.0.0'], $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('reports.index')->withErrors(['publish' => $e->getMessage()]);
        }

        return redirect()->route('reports.index')->with('status', 'Report run published.');
    }

    public function requestExport(Request $request, string $reportRunId): RedirectResponse
    {
        $this->authorize('permission', 'reports:run');

        try {
            $this->reports->requestExport($reportRunId, ['schema_version' => '1.0.0'], $request->user(), (string) Str::uuid(), (string) Str::uuid(), StepUp::isFresh($request));
        } catch (AuthorizationException $e) {
            return $this->stepUpOrError($request, $e, 'export');
        } catch (PlatformResourceException|RepositoryConflictException $e) {
            return redirect()->route('reports.index')->withErrors(['export' => $e->getMessage()]);
        }

        return redirect()->route('reports.index')->with('status', 'Export requested.');
    }

    public function approveExport(Request $request, string $exportId): RedirectResponse
    {
        $this->authorize('permission', 'reports:run');

        try {
            $this->reports->approveExport($exportId, ['schema_version' => '1.0.0'], $request->user(), (string) Str::uuid(), (string) Str::uuid(), StepUp::isFresh($request));
        } catch (AuthorizationException $e) {
            return $this->stepUpOrError($request, $e, 'approve');
        } catch (PlatformResourceException|RepositoryConflictException $e) {
            return redirect()->route('reports.index')->withErrors(['approve' => $e->getMessage()]);
        }

        return redirect()->route('reports.index')->with('status', 'Export approved.');
    }

    public function cancelExport(Request $request, string $exportId): RedirectResponse
    {
        $this->authorize('permission', 'reports:run');
        $reason = (string) $request->input('reason', 'Cancelled from the reports console.');

        try {
            $this->reports->cancelExport($exportId, ['schema_version' => '1.0.0', 'reason' => $reason], $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (PlatformValidationException $e) {
            return redirect()->route('reports.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('reports.index')->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('reports.index')->with('status', 'Export cancelled.');
    }

    public function downloadExport(Request $request, string $exportId)
    {
        $this->authorize('permission', 'reports:read');

        try {
            $result = $this->reports->downloadExport($exportId, $request->user(), (string) Str::uuid());
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('reports.index')->withErrors(['download' => $e->getMessage()]);
        }

        $safeFileName = str_replace('"', '', $result['fileName']);

        return response($result['bytes'], 200, [
            'Content-Type' => $result['contentType'], 'Content-Disposition' => "attachment; filename=\"{$safeFileName}\"", 'Cache-Control' => 'no-store',
        ]);
    }

    public function runModel(Request $request, string $dataProductId): RedirectResponse
    {
        $this->authorize('permission', 'reports:run');
        $payload = ['schema_version' => '1.0.0', 'report_run_id' => (string) $request->input('report_run_id')];

        try {
            $this->dataProducts->runModel($dataProductId, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (PlatformValidationException $e) {
            return redirect()->route('reports.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('reports.index')->withErrors(['model' => $e->getMessage()]);
        }

        return redirect()->route('reports.index')->with('status', 'Analytics model run completed.');
    }

    public function publishDataProduct(Request $request, string $dataProductId): RedirectResponse
    {
        $this->authorize('permission', 'reports:run');
        $payload = ['schema_version' => '1.0.0', 'model_run_id' => (string) $request->input('model_run_id')];

        try {
            $this->dataProducts->publish($dataProductId, $payload, $request->user(), (string) Str::uuid(), (string) Str::uuid());
        } catch (PlatformValidationException $e) {
            return redirect()->route('reports.index')->withErrors(collect($e->errors())->pluck('message', 'path')->all());
        } catch (PlatformResourceException|RepositoryConflictException|AuthorizationException $e) {
            return redirect()->route('reports.index')->withErrors(['publish_snapshot' => $e->getMessage()]);
        }

        return redirect()->route('reports.index')->with('status', 'Data product snapshot published.');
    }

    /** Distinguishes a genuine step-up refusal from every other AuthorizationException these commands can throw. */
    private function stepUpOrError(Request $request, AuthorizationException $e, string $field): RedirectResponse
    {
        if (str_contains($e->getMessage(), 'step-up')) {
            $request->session()->put('url.intended', route('reports.index'));

            return redirect()->route('password.confirm')->with('status', 'This action requires a fresh password confirmation. Please confirm, then retry the action.');
        }

        return redirect()->route('reports.index')->withErrors([$field => $e->getMessage()]);
    }
}
