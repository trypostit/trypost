<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('changelog_entry_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('changelog_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->string('reaction')->nullable();
            $table->timestamps();

            $table->unique(['changelog_entry_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changelog_entry_views');
    }
};
