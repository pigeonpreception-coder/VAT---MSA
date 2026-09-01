<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRole extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'audience', 'risk_tier', 'status'];
}
