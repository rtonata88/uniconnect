<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('source_type', 20); // assignment | quiz | manual
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('max_score', 8, 2)->default(100);
            $table->decimal('weight_percent', 5, 2)->default(0);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['source_type', 'source_id']);
            $table->index('course_id');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_items');
    }
};
