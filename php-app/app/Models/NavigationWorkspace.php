<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A fixed, seed-only catalogue row (natural string id, e.g. 'nav-home') -- see NavigationSeeder. */
class NavigationWorkspace extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    public function folders(): HasMany
    {
        return $this->hasMany(NavigationFolder::class, 'workspace_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NavigationItem::class, 'workspace_id');
    }
}
