<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Excluded from App\Models\Concerns\BelongsToOrganisation (see
 * docs/MIGRATION_MATRIX.md's "Organisation-scope trait retrofit"):
 * App\Services\Document\DocumentService's own read paths
 * (getDocumentVersionHistory/download, both reachable by a taxpayer-
 * scoped `documents:read` actor) fetch a document unscoped by id, then
 * call OrganisationResolver::resolve() to distinguish a genuine 404 from
 * a 403 outside the actor's authorised scope -- the identical
 * fetch-then-check shape that disqualified AuditCase/RefundClaim/etc.
 * The automatic scope would collapse that distinction into an
 * always-404.
 */
class DocumentMetadata extends Model
{
    use HasUuids;

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
