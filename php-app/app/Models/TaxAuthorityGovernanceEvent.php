<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A domain-specific event stream alongside the global audit_events table -- see its own migration's doc comment. */
class TaxAuthorityGovernanceEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime'];
}
