<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ported from db/runtime.ts's `mfa_totp_credentials` table -- one row per
 * user, keyed by `user_id` itself rather than a separate surrogate id,
 * matching the source's own schema exactly.
 */
class MfaTotpCredential extends Model
{
    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'last_used_counter' => 'integer',
        'created_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}
