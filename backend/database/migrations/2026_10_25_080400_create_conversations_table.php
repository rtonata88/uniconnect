<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // direct | group | course
            $table->string('title')->nullable();
            $table->foreignId('course_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            // "minUserId:maxUserId" keeps direct chats unique per pair (PRD §6)
            $table->string('direct_key', 41)->nullable()->unique();
            $table->string('avatar_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('announcements_only')->default(false); // MSG-4
            $table->timestamps();
            $table->softDeletes();
            $table->index('updated_at');
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
