<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessApproval extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class, 'access_request_id');
    }
}
