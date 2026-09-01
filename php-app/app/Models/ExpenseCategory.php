<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseCategory extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['requires_receipt' => 'boolean', 'created_at' => 'datetime'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
