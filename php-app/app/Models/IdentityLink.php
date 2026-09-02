<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityLink extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'provider_id', 'subject', 'email_at_link', 'assurance_level', 'status', 'linked_at', 'last_authenticated_at'];

    protected $casts = ['linked_at' => 'datetime', 'last_authenticated_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class, 'provider_id');
    }
}
