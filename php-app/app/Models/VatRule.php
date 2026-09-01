<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VatRule extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'proposed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];
}
