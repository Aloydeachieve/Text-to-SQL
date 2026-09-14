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
            $table->string('visibility', 20)->default('private')->after('is_demo');
            $table->index(['company_id', 'visibility']);
        });

        Schema::table('dashboards', function (Blueprint $table) {
            $table->string('visibility', 20)->default('private')->after('description');
            $table->index(['company_id', 'visibility']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saved_queries', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'visibility']);
            $table->dropColumn('visibility');
        });

        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'visibility']);
            $table->dropColumn('visibility');
        });
    }
};
