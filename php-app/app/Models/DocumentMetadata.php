<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentMetadata extends Model
{
    use BelongsToOrganisation, HasUuids;

    // Set explicitly rather than relying on Eloquent's own pluralisation
    // of "DocumentMetadata" (an uncountable noun -- "metadata" has no
    // real plural), matching db/runtime.ts's own table name exactly.
    protected $table = 'document_metadata';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['legal_hold' => 'boolean', 'uploaded_at' => 'datetime', 'retained_until' => 'datetime', 'scanned_at' => 'datetime'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_document_id');
    }
}
