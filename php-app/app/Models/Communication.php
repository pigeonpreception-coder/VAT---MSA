<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Communication extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    // Microsecond precision, matching the `occurred_at` column's own
    // timestamp(6) -- see that migration's own note on why: GetInbox's
    // "latest message" ordering needs sub-second resolution to break ties
    // between two replies posted within the same wall-clock second.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = ['occurred_at' => 'datetime'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommunicationThread::class, 'thread_id');
    }
}
