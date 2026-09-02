<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `access_permissions` table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_permissions', function (Blueprint $table) {
            $table->string('code', 60)->primary();
            $table->string('resource', 60);
            $table->string('action', 40);
            $table->text('description');
            $table->string('classification', 30);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_permissions');
    }
};
