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
            $table->string('request_id', 64)->nullable()->after('id')->index();
            $table->integer('rows_returned')->nullable()->after('execution_time_ms');
            $table->boolean('truncated')->default(false)->after('rows_returned');
            $table->string('risk_level', 20)->nullable()->after('truncated');
            $table->string('error_code', 50)->nullable()->after('error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn([
                'request_id',
                'rows_returned',
                'truncated',
                'risk_level',
                'error_code',
            ]);
        });
    }
};
