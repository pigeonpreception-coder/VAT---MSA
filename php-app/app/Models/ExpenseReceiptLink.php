<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `expense_receipt_links` table (Module 5 Phase E) -- an append-only
 * link table: one immutable receipt per draft expense (enforced by the
 * unique indexes on `expense_id`/`document_id` in this table's own
 * migration). First and only write path:
 * App\Services\Business\ExpenseService::linkReceipt().
 */
class ExpenseReceiptLink extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['linked_at' => 'datetime'];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentMetadata::class, 'document_id');
    }

    public function linkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
