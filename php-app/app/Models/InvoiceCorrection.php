<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceCorrection extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function correctionInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'correction_invoice_id');
    }
}
