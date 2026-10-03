<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ideas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained(indexName: 'idea_cascade_workspace_id_foreign')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('idea_stage_id')->nullable()->constrained('idea_stages')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('media')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'idea_stage_id', 'position']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ideas');
    }
};
