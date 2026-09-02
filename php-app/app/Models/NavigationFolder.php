<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A fixed, seed-only catalogue row (natural string id, e.g. 'folder-home-dashboard') -- see NavigationSeeder. */
class NavigationFolder extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(NavigationWorkspace::class, 'workspace_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NavigationItem::class, 'folder_id');
    }
}
