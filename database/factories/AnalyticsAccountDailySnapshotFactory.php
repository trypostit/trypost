<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\ObservationProvenance;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsAccountDailySnapshot>
 */
class AnalyticsAccountDailySnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'social_account_id' => null,
            'social_account_key' => fake()->uuid(),
            'network' => Platform::Instagram->network(),
            'platform_user_id' => fake()->uuid(),
            'platform' => Platform::Instagram,
            'account_display_name' => fake()->name(),
            'account_username' => fake()->userName(),
            'account_avatar_url' => fake()->imageUrl(),
            'date' => today(),
            'followers_count' => fake()->numberBetween(0, 100000),
            'metrics' => null,
            'provenance' => ObservationProvenance::Actual,
            'precision' => MetricPrecision::Exact,
            'provider_observed_at' => now(),
            'collected_at' => now(),
        ];
    }
}
