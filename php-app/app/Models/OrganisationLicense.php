<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganisationLicense extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    // Microsecond precision, matching the `effective_from` column's own
    // timestamp(6) -- see that migration's own note: LicensingService's
    // getLicense() picks an organisation's current licence by
    // `ORDER BY effective_from DESC LIMIT 1`, and needs sub-second
    // resolution to break ties between two licences created within the
    // same wall-clock second (e.g. onboarding immediately followed by an
    // upgrade).
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'effective_from' => 'datetime', 'effective_to' => 'datetime', 'grace_ends_at' => 'datetime', 'updated_at' => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(LicensePlan::class, 'license_plan_id');
    }
}
