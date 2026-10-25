<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            // idempotency key of the latest (re)submission
            $table->string('client_uuid', 36)->unique();
            $table->text('text')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->boolean('is_late')->default(false);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->decimal('score', 8, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['assignment_id', 'student_id']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
