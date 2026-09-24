<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsSyncState>
 */
class AnalyticsSyncStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'identity_key' => hash('sha256', fake()->uuid()),
            'collector' => SyncCollector::PublicationBackfill,
            'status' => SyncStatus::Pending,
            'checkpoint' => null,
            'target_since' => now()->subYear(),
            'oldest_reached_at' => null,
            'high_watermark_at' => null,
            'last_success_at' => null,
            'last_error_category' => null,
        ];
    }
}
