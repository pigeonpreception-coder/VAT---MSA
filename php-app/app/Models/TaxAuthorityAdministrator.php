<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** No command in this module creates a row here (seed-only) -- see App\Models\TaxAuthority's own doc comment for why HasUuids is omitted. */
class TaxAuthorityAdministrator extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['effective_from' => 'datetime', 'effective_to' => 'datetime'];
}
