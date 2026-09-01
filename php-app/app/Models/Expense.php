<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['expense_date' => 'date', 'created_at' => 'datetime', 'approved_at' => 'datetime'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(BusinessParty::class, 'supplier_party_id');
    }
}
