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
        Schema::create('analytics_publication_daily_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('analytics_publication_id');
            $table->date('snapshot_date');
            $table->timestamp('collected_at');
            $table->timestamp('provider_observed_at')->nullable();
            $table->json('metrics')->nullable();
            $table->bigInteger('reactions_count')->nullable();
            $table->bigInteger('comments_count')->nullable();
            $table->bigInteger('shares_count')->nullable();
            $table->bigInteger('saves_count')->nullable();
            $table->bigInteger('views_count')->nullable();
            $table->bigInteger('impressions_count')->nullable();
            $table->bigInteger('reach_count')->nullable();
            $table->bigInteger('engagement_count')->nullable();
            $table->bigInteger('exposure_count')->nullable();
            $table->string('exposure_kind', 32)->nullable();
            $table->bigInteger('watch_time_milliseconds')->nullable();
            $table->bigInteger('average_watch_time_milliseconds')->nullable();
            $table->timestamps();

            $table->foreign('analytics_publication_id', 'analytics_publication_daily_parent_fk')
                ->references('id')->on('analytics_publications')->cascadeOnDelete();
            $table->unique(
                ['analytics_publication_id', 'snapshot_date'],
                'analytics_publication_daily_identity_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_publication_daily_snapshots');
    }
};
