<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsPublicationDailySnapshot>
 */
class AnalyticsPublicationDailySnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analytics_publication_id' => AnalyticsPublication::factory(),
            'snapshot_date' => today(),
            'collected_at' => now(),
            'provider_observed_at' => now(),
            'metrics' => null,
        ];
    }
}
