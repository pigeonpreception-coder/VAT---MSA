<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IdentityProvider extends Model
{
    use HasUuids;

    protected $fillable = ['provider_key', 'display_name', 'provider_type', 'authority_level', 'issuer', 'status', 'configuration_status'];
}
