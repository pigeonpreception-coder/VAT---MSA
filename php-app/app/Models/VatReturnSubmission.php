<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VatReturnSubmission extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['requested_at' => 'datetime', 'submitted_at' => 'datetime', 'acknowledged_at' => 'datetime'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(VatReturnVersion::class, 'vat_return_version_id');
    }
}
