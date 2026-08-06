<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ruklab_snapshots', function (Blueprint $table): void {
            $table->id();
            // The model class rather than a table name: a site can rename a
            // table, and the copy should still say what it was a copy of.
            $table->string('model');
            // A string, because not every model on every site keys on an int.
            $table->string('record_id');
            $table->json('values');
            $table->timestamp('created_at')->nullable();

            $table->index(['model', 'record_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruklab_snapshots');
    }
};
