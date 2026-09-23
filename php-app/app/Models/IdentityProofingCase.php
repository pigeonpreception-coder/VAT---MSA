<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IdentityProofingCase extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function registrationApplication(): BelongsTo
    {
        return $this->belongsTo(RegistrationApplication::class);
    }

    public function mismatchCase(): HasOne
    {
        return $this->hasOne(IdentityMismatchCase::class, 'proofing_case_id');
    }
}
