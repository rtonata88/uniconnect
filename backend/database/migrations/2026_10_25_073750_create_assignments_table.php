<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestamp('due_at');
            $table->decimal('max_score', 8, 2)->default(100);
            $table->boolean('allow_late')->default(false);
            $table->unsignedTinyInteger('late_penalty_percent')->default(0);
            $table->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            // NOT-4: reminders already sent (so restarts/replicas never send twice)
            $table->timestamp('reminded_24h_at')->nullable();
            $table->timestamp('reminded_1h_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['course_id', 'due_at']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
