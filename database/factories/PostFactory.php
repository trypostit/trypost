<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A post without a channel is a draft that was never sent anywhere. Use
 * forAccount() or a network state (linkedin(), x(), ...) for a post with a
 * channel; a network state creates the channel in the post's workspace.
 *
 * @extends Factory<Post>
 */
class PostFactory extends Factory
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
            'user_id' => User::factory(),
            'content' => '',
            'media' => [],
            'status' => PostStatus::Draft,
        ];
    }

    public function forAccount(SocialAccount $account, ?ContentType $contentType = null): static
    {
        return $this->state(fn (array $attributes) => [
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'content_type' => $contentType ?? ContentType::defaultFor($account->platform),
            'meta' => $attributes['meta'] ?? [],
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Draft,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Scheduled,
            'schedule_mode' => ScheduleMode::Custom,
            'scheduled_at' => now()->addDay(),
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::PendingApproval,
            'schedule_mode' => ScheduleMode::Custom,
            'scheduled_at' => now()->addDay(),
            'approval_requested_at' => now(),
        ]);
    }

    public function publishing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Publishing,
            'publish_status' => PublishStatus::Publishing,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Published,
            'publish_status' => PublishStatus::Published,
            'platform_post_id' => $this->faker->uuid(),
            'platform_url' => $this->faker->url(),
            'published_at' => now(),
        ]);
    }

    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => Origin::Network,
            'user_id' => null,
            'status' => PostStatus::Published,
            'publish_status' => PublishStatus::Published,
            'platform_post_id' => $this->faker->uuid(),
            'published_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Failed,
            'publish_status' => PublishStatus::Failed,
            'error_message' => 'Failed to publish',
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Publishing,
            'publish_status' => PublishStatus::PendingReview,
            'submitted_at' => now(),
        ]);
    }

    public function linkedin(): static
    {
        return $this->onNetwork(Platform::LinkedIn, ContentType::LinkedInPost, 'linkedin');
    }

    public function x(): static
    {
        return $this->onNetwork(Platform::X, ContentType::XPost, 'x');
    }

    public function instagram(): static
    {
        return $this->onNetwork(Platform::Instagram, ContentType::InstagramFeed, 'instagram');
    }

    public function bluesky(): static
    {
        return $this->onNetwork(Platform::Bluesky, ContentType::BlueskyPost, 'bluesky');
    }

    public function mastodon(): static
    {
        return $this->onNetwork(Platform::Mastodon, ContentType::MastodonPost, 'mastodon');
    }

    public function threads(): static
    {
        return $this->onNetwork(Platform::Threads, ContentType::ThreadsPost, 'threads');
    }

    public function tiktok(): static
    {
        return $this->onNetwork(Platform::TikTok, ContentType::TikTokVideo, 'tiktok', ['privacy_level' => PrivacyLevel::SelfOnly->value]);
    }

    public function youtube(): static
    {
        return $this->onNetwork(Platform::YouTube, ContentType::YouTubeShort, 'youtube');
    }

    public function pinterest(): static
    {
        return $this->onNetwork(Platform::Pinterest, ContentType::PinterestPin, 'pinterest');
    }

    public function pinterestVideoPin(): static
    {
        return $this->onNetwork(Platform::Pinterest, ContentType::PinterestVideoPin, 'pinterest');
    }

    public function pinterestCarousel(): static
    {
        return $this->onNetwork(Platform::Pinterest, ContentType::PinterestCarousel, 'pinterest');
    }

    public function googleBusiness(): static
    {
        return $this->onNetwork(Platform::GoogleBusiness, ContentType::GoogleBusinessPost, 'googleBusiness');
    }

    public function facebook(): static
    {
        return $this->onNetwork(Platform::Facebook, ContentType::FacebookPost, 'facebook');
    }

    public function facebookReel(): static
    {
        return $this->onNetwork(Platform::Facebook, ContentType::FacebookReel, 'facebook');
    }

    public function facebookStory(): static
    {
        return $this->onNetwork(Platform::Facebook, ContentType::FacebookStory, 'facebook');
    }

    public function discord(): static
    {
        return $this->onNetwork(Platform::Discord, ContentType::DiscordMessage, 'discord');
    }

    public function telegram(): static
    {
        return $this->onNetwork(Platform::Telegram, ContentType::defaultFor(Platform::Telegram), 'telegram');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function onNetwork(Platform $platform, ContentType $contentType, string $accountState, array $meta = []): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => $platform,
            'content_type' => $contentType,
            'meta' => [...$meta, ...($attributes['meta'] ?? [])],
            'social_account_id' => isset($attributes['social_account_id'])
                ? $attributes['social_account_id']
                : fn (array $resolved) => SocialAccount::factory()->{$accountState}()->state(['workspace_id' => $resolved['workspace_id']]),
        ]);
    }
}
