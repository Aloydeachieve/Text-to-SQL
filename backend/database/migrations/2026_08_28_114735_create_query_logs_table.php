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
        Schema::create('query_logs', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->text('generated_sql')->nullable();
            $table->boolean('passed_guardrails');
            $table->string('execution_status'); // 'success', 'failed', 'blocked'
            $table->integer('execution_time_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('confidence_score', 3, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_logs');
    }
};
