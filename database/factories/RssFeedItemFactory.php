<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RssFeed;
use App\Models\RssFeedItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RssFeedItem>
 */
class RssFeedItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->unique()->url();

        return [
            'rss_feed_id' => RssFeed::factory(),
            'guid_hash' => RssFeedItem::hashGuid($url),
            'title' => fake()->sentence(6),
            'url' => $url,
            'excerpt' => fake()->paragraph(),
            'image_url' => null,
            'image_checked_at' => null,
            'author' => null,
            'published_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
