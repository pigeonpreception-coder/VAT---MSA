<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofits FK constraints that earlier Phase 10 migrations documented as
 * deliberately deferred, now that `projects` and `products` finally exist:
 * journal_lines.project_id and expenses.project_id -> projects(id) (see
 * those tables' own migrations), and quotation_lines.product_id ->
 * products(id) (see that migration's own note). No data migration needed
 * -- every existing row in these columns is NULL up to this point in the
 * migration history, since nothing that could populate them existed yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects');
        });
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
    }
};
