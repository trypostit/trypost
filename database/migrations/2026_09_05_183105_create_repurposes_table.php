<?php

declare(strict_types=1);

use App\Enums\Repurpose\SourceFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repurposes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('source_social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->string('source_format')->default(SourceFormat::Reel->value);
            $table->json('destinations');
            $table->string('status');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'source_social_account_id', 'source_format'],
                'repurposes_source_format_unique',
            );
            $table->index(['status', 'next_poll_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repurposes');
    }
};
