<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxAuthorityOnboardingCase extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'activated_at' => 'datetime',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
    ];

    public function decisions(): HasMany
    {
        return $this->hasMany(TaxAuthorityOnboardingDecision::class, 'onboarding_case_id');
    }
}
