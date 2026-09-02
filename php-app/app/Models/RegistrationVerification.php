<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationVerification extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'checked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function registrationApplication(): BelongsTo
    {
        return $this->belongsTo(RegistrationApplication::class);
    }
}
