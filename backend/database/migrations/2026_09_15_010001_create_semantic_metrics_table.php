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
        Schema::create('semantic_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('definition');
            $table->string('source_table');
            $table->string('source_column');
            $table->string('aggregation')->default('SUM'); // SUM, COUNT, AVG, MIN, MAX, COUNT_DISTINCT
            $table->string('filter_condition')->nullable(); // e.g. "status = 'completed'"
            $table->string('date_column')->nullable(); // e.g. "paid_at" or "created_at"
            $table->boolean('is_active')->default(true);
            $table->boolean('is_source_of_truth')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'source_table']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('semantic_metrics');
    }
};
