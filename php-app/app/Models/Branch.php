<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    use HasUuids;

    protected $fillable = ['organisation_id', 'code', 'name', 'address', 'status', 'is_head_office'];

    protected $casts = ['is_head_office' => 'boolean'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
