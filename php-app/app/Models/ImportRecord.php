<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ported from db/runtime.ts's `import_records` table -- business-
 * repository.ts's customs-import declaration record. Originally read-only
 * (a full-repo grep of the TypeScript source confirmed no command ever
 * wrote to it there), until the user's own explicit request to
 * autonomously pull foreign-invoice declarations from NamRA's E-Tariff
 * border system -- `App\Services\Business\ForeignInvoiceService` is the
 * one write path this table now has, gated behind a real external port
 * (`App\Integrations\Etariff\EtariffPort`) rather than a source-fidelity
 * concern. `source`/`etariff_reference`/`verification_status`/`pulled_at`
 * are this feature's own added columns (see that migration's own doc
 * comment); every other column is still the original ported shape.
 */
class ImportRecord extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['declaration_date' => 'date', 'created_at' => 'datetime', 'pulled_at' => 'datetime'];

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
