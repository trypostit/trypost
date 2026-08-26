<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
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
            'platform' => Platform::LinkedIn,
            'platform_user_id' => $this->faker->uuid(),
            'username' => $this->faker->userName(),
            'display_name' => $this->faker->name(),
            'access_token' => $this->faker->sha256(),
            'refresh_token' => $this->faker->sha256(),
            'token_expires_at' => now()->addDays(60),
            'scopes' => [],
            'meta' => [],
            'status' => Status::Connected,
        ];
    }

    public function linkedin(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::LinkedIn,
            'scopes' => Platform::LinkedIn->requiredPublishScopes(),
        ]);
    }

    public function linkedinPage(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::LinkedInPage,
            'scopes' => Platform::LinkedInPage->requiredPublishScopes(),
        ]);
    }

    public function x(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::X,
            'scopes' => Platform::X->requiredPublishScopes(),
        ]);
    }

    public function tiktok(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::TikTok,
            'scopes' => Platform::TikTok->requiredPublishScopes(),
        ]);
    }

    public function youtube(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::YouTube,
            'scopes' => Platform::YouTube->requiredPublishScopes(),
        ]);
    }

    public function facebook(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Facebook,
            'scopes' => Platform::Facebook->requiredPublishScopes(),
        ]);
    }

    public function instagram(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Instagram,
            'scopes' => Platform::Instagram->requiredPublishScopes(),
        ]);
    }

    public function instagramFacebook(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::InstagramFacebook,
            'scopes' => Platform::InstagramFacebook->requiredPublishScopes(),
        ]);
    }

    public function threads(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Threads,
            'scopes' => Platform::Threads->requiredPublishScopes(),
        ]);
    }

    public function pinterest(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Pinterest,
            'scopes' => Platform::Pinterest->requiredPublishScopes(),
        ]);
    }

    public function bluesky(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Bluesky,
            'scopes' => Platform::Bluesky->requiredPublishScopes(),
            'token_expires_at' => now()->addHours(2),
            'meta' => [
                'service' => 'https://bsky.social',
                'identifier' => 'test@example.com',
                'password' => encrypt('test-app-password'),
            ],
        ]);
    }

    public function mastodon(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Mastodon,
            'scopes' => Platform::Mastodon->requiredPublishScopes(),
            'token_expires_at' => null, // Mastodon tokens don't expire
            'meta' => [
                'instance' => 'https://mastodon.social',
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
            ],
        ]);
    }

    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Telegram,
            'scopes' => Platform::Telegram->requiredPublishScopes(),
            'token_expires_at' => null, // the shared bot token never expires
            'access_token' => '',
            'refresh_token' => '',
            'username' => 'mychannel',
            'meta' => [
                'chat_id' => '-1001234567890',
                'username' => 'mychannel',
                'type' => 'channel',
            ],
        ]);
    }

    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => Platform::Discord,
            'scopes' => Platform::Discord->requiredPublishScopes(),
            'token_expires_at' => null, // posting uses the global bot token
            'access_token' => '',
            'refresh_token' => '',
            'platform_user_id' => '999000111', // guild id
            'display_name' => 'My Server',
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Status::Disconnected,
            'error_message' => 'Token expired',
            'disconnected_at' => now(),
        ]);
    }

    public function tokenExpired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Status::TokenExpired,
            'token_expires_at' => now()->subDay(),
        ]);
    }
}
