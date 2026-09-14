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
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained('dashboards')->cascadeOnDelete();
            $table->foreignId('saved_query_id')->constrained('saved_queries')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('visualization_type', 30)->nullable();
            $table->integer('position')->default(0);
            $table->integer('width')->default(1);
            $table->integer('height')->default(1);
            $table->timestamps();

            $table->index(['dashboard_id', 'position']);
            $table->index(['saved_query_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
