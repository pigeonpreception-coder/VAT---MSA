<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'date',
        'created_at' => 'datetime',
        'certified_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class, 'supplier_taxpayer_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class, 'customer_taxpayer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_number');
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /** The correction row where THIS invoice is the correction document (i.e. this invoice IS a credit/debit note). */
    public function correctionOf(): HasOne
    {
        return $this->hasOne(InvoiceCorrection::class, 'correction_invoice_id');
    }
}
