<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // mcq | true_false | short_answer
            $table->text('prompt');
            $table->decimal('points', 6, 2)->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('correct_text')->nullable();
            $table->timestamps();
            $table->index(['quiz_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
