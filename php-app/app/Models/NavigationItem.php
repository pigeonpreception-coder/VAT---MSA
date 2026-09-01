<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fixed, seed-only catalogue row (natural string id, e.g. 'nitem-dashboard') -- see NavigationSeeder. */
class NavigationItem extends Model
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

    public function folder(): BelongsTo
    {
        return $this->belongsTo(NavigationFolder::class, 'folder_id');
    }
}
