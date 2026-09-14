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
        Schema::table('query_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->after('user_id')->constrained('companies')->nullOnDelete();
            $table->foreignId('database_connection_id')->nullable()->after('company_id')->constrained('database_connections')->nullOnDelete();

            $table->index(['company_id', 'created_at']);
            $table->index(['database_connection_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['company_id']);
            $table->dropForeign(['database_connection_id']);
            $table->dropIndex(['company_id', 'created_at']);
            $table->dropIndex(['database_connection_id', 'created_at']);
            $table->dropColumn(['user_id', 'company_id', 'database_connection_id']);
        });
    }
};
