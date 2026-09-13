<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsDelivery extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'dispatched_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'vehicle_asset_id');
    }
}
