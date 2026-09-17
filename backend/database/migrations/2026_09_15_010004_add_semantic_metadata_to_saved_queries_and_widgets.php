<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('saved_queries', function (Blueprint $table) {
            $table->foreignId('metric_id')->nullable()->after('database_connection_id')->constrained('semantic_metrics')->onDelete('set null');
            $table->json('semantic_snapshot')->nullable()->after('metric_id');
        });

        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->foreignId('metric_id')->nullable()->after('saved_query_id')->constrained('semantic_metrics')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saved_queries', function (Blueprint $table) {
            $table->dropForeign(['metric_id']);
            $table->dropColumn(['metric_id', 'semantic_snapshot']);
        });

        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropForeign(['metric_id']);
            $table->dropColumn(['metric_id']);
        });
    }
};
