<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostPlatform>
 */
class PostPlatformFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'social_account_id' => SocialAccount::factory(),
            'enabled' => true,
            'platform' => Platform::LinkedIn,
            'content_type' => ContentType::LinkedInPost,
            'status' => Status::Pending,
            'meta' => [],
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Status::Published,
            'platform_post_id' => $this->faker->uuid(),
            'platform_url' => $this->faker->url(),
            'published_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Status::Failed,
            'error_message' => 'Failed to publish',
        ]);
    }

    public function linkedin(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::LinkedIn,
        ]);
    }

    public function x(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::X,
            'content_type' => ContentType::XPost,
        ]);
    }

    public function instagram(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Instagram,
            'content_type' => ContentType::InstagramFeed,
        ]);
    }

    public function instagramFacebook(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::InstagramFacebook,
            'content_type' => ContentType::InstagramFeed,
        ]);
    }

    public function bluesky(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Bluesky,
            'content_type' => ContentType::BlueskyPost,
        ]);
    }

    public function mastodon(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Mastodon,
            'content_type' => ContentType::MastodonPost,
        ]);
    }

    public function threads(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Threads,
            'content_type' => ContentType::ThreadsPost,
        ]);
    }

    public function tiktok(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::TikTok,
            'content_type' => ContentType::TikTokVideo,
            'meta' => ['privacy_level' => 'SELF_ONLY'],
        ]);
    }

    public function youtube(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::YouTube,
            'content_type' => ContentType::YouTubeShort,
        ]);
    }

    public function pinterest(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Pinterest,
            'content_type' => ContentType::PinterestPin,
        ]);
    }

    public function pinterestVideoPin(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Pinterest,
            'content_type' => ContentType::PinterestVideoPin,
        ]);
    }

    public function pinterestCarousel(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Pinterest,
            'content_type' => ContentType::PinterestCarousel,
        ]);
    }

    public function facebook(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Facebook,
            'content_type' => ContentType::FacebookPost,
        ]);
    }

    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Discord,
            'content_type' => ContentType::DiscordMessage,
        ]);
    }

    public function facebookReel(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Facebook,
            'content_type' => ContentType::FacebookReel,
        ]);
    }

    public function facebookStory(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Facebook,
            'content_type' => ContentType::FacebookStory,
        ]);
    }
}
