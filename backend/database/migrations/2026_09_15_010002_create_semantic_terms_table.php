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
        Schema::create('semantic_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('term'); // e.g. "sales", "revenue", "cancelled orders"
            $table->foreignId('metric_id')->nullable()->constrained('semantic_metrics')->onDelete('set null');
            $table->string('target_type')->default('metric'); // metric, table, column, filter, concept
            $table->string('target_name'); // e.g. "payments", "status = 'cancelled'"
            $table->text('definition')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->unique(['company_id', 'term']);
            $table->index(['company_id', 'metric_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('semantic_terms');
    }
};
