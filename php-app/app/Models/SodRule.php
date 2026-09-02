<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `organisation_id` is schema-nullable (a NULL row would apply globally,
 * per this migration's own de-facto-dead-column note on its migration),
 * but the only reader (`WorkflowService::decideWorkflowTask`) always
 * filters `where('organisation_id', $organisation->id)` against a real,
 * resolved organisation -- no code path anywhere ever queries or expects
 * a NULL-organisation "global rule" row. The organisation-scope trait's
 * own redundant filter is therefore safe here, unlike
 * `CommunicationThread`/`Communication` (see docs/MIGRATION_MATRIX.md's
 * "Organisation-scope trait: the nullable-column exclusions" section).
 */
class SodRule extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['mandatory' => 'boolean', 'effective_from' => 'datetime', 'created_at' => 'datetime'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
