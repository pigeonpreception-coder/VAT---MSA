<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** code is its own natural primary key (a fixed catalogue code, e.g. PRIMARY), not a UUID -- matches db/runtime.ts's schema exactly. */
class OrganisationAdministratorRole extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['protected' => 'boolean'];
}
