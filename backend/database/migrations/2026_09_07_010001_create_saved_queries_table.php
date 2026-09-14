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
        Schema::create('saved_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('database_connection_id')->nullable()->constrained('database_connections')->nullOnDelete();
            $table->string('target_database_name')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('natural_language_question');
            $table->text('sql');
            $table->string('dialect', 20)->default('mysql');
            $table->string('result_visualization_type', 30)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'database_connection_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_queries');
    }
};
