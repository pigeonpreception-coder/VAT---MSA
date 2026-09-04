<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ported from db/runtime.ts's `import_records` table -- business-
 * repository.ts's customs-import declaration record. A full-repo grep of
 * the TypeScript source confirms it is only ever read (by
 * `getBusinessPlatformSnapshot` and the source's own `app/operations/
 * page.tsx` fourth panel) and never written by any command -- the same
 * seed/read-only posture already established for `report_definitions`/
 * `data_products`/`feature_flags` elsewhere in this migration. No
 * corresponding service: a plain read-only model is all any caller needs.
 */
class ImportRecord extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['declaration_date' => 'date', 'created_at' => 'datetime'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function evidenceDocument(): BelongsTo
    {
        return $this->belongsTo(DocumentMetadata::class, 'evidence_document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
