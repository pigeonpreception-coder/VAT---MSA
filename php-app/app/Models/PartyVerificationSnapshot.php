<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyVerificationSnapshot extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'taxpayer_active' => 'boolean', 'organisation_active' => 'boolean', 'can_act_as_seller' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(BusinessParty::class, 'party_id');
    }
}
