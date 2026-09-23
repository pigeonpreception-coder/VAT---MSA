<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `expense_decisions` table (Module 5 Phase E) -- one row per decided
 * expense (the unique index on `expense_id` is the real enforcement: a
 * DRAFT expense can be decided at most once). First and only write path:
 * App\Services\Business\ExpenseService::decide().
 */
class ExpenseDecision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
