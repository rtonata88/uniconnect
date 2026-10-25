<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DIST-4: a row written on the primary and read back on the replica measures replication lag. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replication_probes', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->timestamp('written_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replication_probes');
    }
};
