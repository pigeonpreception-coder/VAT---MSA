<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `test_runs` table (Module 10 Phase D) -- an `api_clients` sandbox
 * conformance-check battery, genuinely distinct from Phase 12 slice 5's
 * `testWorkflowVersion` (which walks a workflow definition with no
 * persistence at all -- see App\Services\Workflow\WorkflowService's own
 * doc comment). First real write path:
 * App\Services\Developer\DeveloperPlatformService::runConformance().
 */
class TestRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'run_at' => 'datetime',
    ];

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    /** @return list<array{code: string, status: string, rationale: string}> */
    public function checkList(): array
    {
        $decoded = json_decode((string) $this->checks, true);

        return is_array($decoded) ? $decoded : [];
    }
}
