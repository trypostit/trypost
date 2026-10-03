<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RssFeed\Format;
use App\Models\RssFeed;
use App\Models\RssFeedCollection;
use App\Models\Workspace;
use App\Support\RssFeedUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RssFeed>
 */
class RssFeedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $host = fake()->unique()->domainName();

        return [
            'workspace_id' => Workspace::factory(),
            'rss_feed_collection_id' => null,
            'url' => "https://{$host}/feed",
            'url_hash' => fn (array $attributes): string => RssFeedUrl::hash($attributes['url']),
            'format' => Format::Rss,
            'title' => fake()->company(),
            'custom_title' => null,
            'site_url' => "https://{$host}",
            'icon_url' => "https://{$host}/favicon.ico",
            'last_fetched_at' => null,
            'last_succeeded_at' => null,
            'next_fetch_at' => null,
            'refresh_requested_at' => null,
            'consecutive_failures' => 0,
            'last_error' => null,
        ];
    }

    public function inCollection(RssFeedCollection $collection): static
    {
        return $this->state(fn (array $attributes) => [
            'workspace_id' => $collection->workspace_id,
            'rss_feed_collection_id' => $collection->id,
        ]);
    }
}
