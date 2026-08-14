<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ruklab_redirects', function (Blueprint $table): void {
            $table->id();
            // Always a path on this site, tidied on the way in: leading slash,
            // no trailing one. Not unique in the database, because an inactive
            // rule for a path that later gets a new active one is legitimate
            // and the check that matters lives where the message can explain
            // itself.
            $table->string('source', 500);
            // Empty for a 410 or a 451, which describe an absence rather than
            // a destination.
            $table->string('target', 500)->default('');
            $table->unsignedSmallInteger('code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruklab_redirects');
    }
};
