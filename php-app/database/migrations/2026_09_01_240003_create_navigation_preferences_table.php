<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `navigation_preferences` table --
 * `saveNavigationPreference`'s own upsert target, a genuine UUID-keyed
 * row (unlike the three fixed-catalogue navigation_* tables above) since
 * every user can have their own. `organisation_id` is nullable to match
 * the source; MySQL/MariaDB InnoDB, like the source's own SQLite, treats
 * each NULL in a UNIQUE index as distinct, so the plain three-column
 * unique index below reproduces the source's own upsert-key semantics
 * without any extra handling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->string('preference_type', 60);
            $table->text('value');
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['user_id', 'organisation_id', 'preference_type'], 'navigation_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_preferences');
    }
};
