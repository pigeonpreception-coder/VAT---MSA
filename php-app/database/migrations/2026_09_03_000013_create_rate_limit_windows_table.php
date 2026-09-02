<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `rate_limit_windows` table -- the source's
 * own sliding-window rate-limit bucket store (`lib/security/request.ts`'s
 * `enforceRateLimits`), which this migration has not ported (rate
 * limiting is called out as an explicitly deferred, orthogonal concern in
 * every controller doc comment that mentions it, e.g.
 * App\Http\Controllers\Document\DocumentController). The source itself
 * declares no primary key on this table (a bucket_key/window_start pair
 * upserted directly) -- mirrored exactly rather than inventing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_limit_windows', function (Blueprint $table) {
            $table->string('bucket_key');
            $table->unsignedBigInteger('window_start');
            $table->unsignedInteger('request_count');
            $table->unsignedBigInteger('expires_at');

            $table->unique(['bucket_key', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_windows');
    }
};
