<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsPublication>
 */
class AnalyticsPublicationFactory extends Factory
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
            'post_platform_id' => null,
            'network' => Platform::Instagram->network(),
            'platform_user_id' => fake()->uuid(),
            'platform' => Platform::Instagram,
            'remote_id' => fake()->uuid(),
            'provider_published_at' => now()->subDay(),
            'origin' => PublicationOrigin::External,
            'content_type' => PublicationContentType::Image,
            'availability' => PublicationAvailability::Available,
            'provider_content_type' => 'IMAGE',
            'permalink' => fake()->url(),
            'excerpt' => fake()->sentence(),
            'preview_metadata' => null,
            'account_display_name' => fake()->name(),
            'account_username' => fake()->userName(),
            'account_avatar_url' => fake()->imageUrl(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'provider_synced_at' => now(),
            'provider_metadata' => null,
        ];
    }
}
