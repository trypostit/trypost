<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analytics_account_daily_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('social_account_key');
            $table->string('network', 32);
            $table->string('platform_user_id', 191);
            $table->string('platform', 32);
            $table->string('account_display_name')->nullable();
            $table->string('account_username')->nullable();
            $table->text('account_avatar_url')->nullable();
            $table->date('date');
            $table->bigInteger('followers_count')->nullable();
            $table->json('metrics')->nullable();
            $table->string('provenance', 32);
            $table->string('precision', 32);
            $table->timestamp('provider_observed_at')->nullable();
            $table->timestamp('collected_at');
            $table->timestamps();

            $table->unique(['social_account_key', 'date']);
            $table->index(['workspace_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_account_daily_snapshots');
    }
};
