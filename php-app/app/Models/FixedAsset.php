<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAsset extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'acquisition_date' => 'date', 'disposed_at' => 'datetime',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'custodian_employee_id');
    }
}
