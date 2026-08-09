<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_post_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('social_account_id')->nullable();
            $table->string('format');
            $table->string('template')->default('image_card');
            $table->unsignedInteger('image_count')->default(0);
            $table->boolean('apply_brand_visuals')->default(true);
            $table->date('scheduled_date')->nullable();
            $table->text('prompt');
            $table->json('structured')->nullable();
            $table->string('status')->default('preparing');
            $table->uuid('post_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_post_drafts');
    }
};
