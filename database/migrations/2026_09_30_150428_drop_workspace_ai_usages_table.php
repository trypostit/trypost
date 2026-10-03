<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('workspace_ai_usages');
    }

    public function down(): void
    {
        Schema::create('workspace_ai_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('workspace_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('post_id')->nullable();
            $table->string('type');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->index(['account_id', 'created_at']);
            $table->index(['account_id', 'type', 'created_at']);
        });
    }
};
