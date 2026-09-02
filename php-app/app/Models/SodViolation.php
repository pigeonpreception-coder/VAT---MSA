<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SodViolation extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['detected_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SodRule::class, 'sod_rule_id');
    }
}
