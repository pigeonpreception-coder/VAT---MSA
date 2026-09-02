<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VatTransaction extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    // Microsecond precision, matching the `created_at` column's own
    // timestamp(6) -- see that migration's own note: GetTransactionTimeline
    // orders a lineage's events by this column, and needs sub-second
    // resolution to break ties between two transactions posted within the
    // same wall-clock second (e.g. a certification followed immediately by
    // its own correction or cancellation).
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = ['created_at' => 'datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function referenceTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_transaction_id');
    }
}
