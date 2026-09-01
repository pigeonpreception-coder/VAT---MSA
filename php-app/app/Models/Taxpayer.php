<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Taxpayer extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'vat_number', 'tin', 'legal_name', 'trading_name', 'taxpayer_type',
        'vat_status', 'return_frequency', 'address', 'email',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function organisation(): HasOne
    {
        return $this->hasOne(Organisation::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(TaxpayerIdentifier::class);
    }

    public function isActive(): bool
    {
        return $this->vat_status === 'ACTIVE';
    }
}
