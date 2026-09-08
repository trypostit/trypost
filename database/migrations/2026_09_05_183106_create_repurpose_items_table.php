<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repurpose_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('repurpose_id')->constrained('repurposes')->cascadeOnDelete();
            $table->string('source_media_id');
            $table->text('source_permalink')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->string('status');
            $table->string('reason')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['repurpose_id', 'source_media_id']);
            $table->index(['repurpose_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repurpose_items');
    }
};
