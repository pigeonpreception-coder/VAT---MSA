<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `user_invitations` table (Module 1) -- an identity-level, claim-token
 * invitation flow genuinely distinct from Phase 12 slice 2's `employees`/
 * `inviteEmployee` (that table manages an organisation's own staff roster;
 * this one is the lower-level "invite someone to claim a role_code via a
 * token" primitive). First real write path: App\Services\Identity\
 * UserInvitationService.
 */
class UserInvitation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'invited_at' => 'datetime',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function claimedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }
}
