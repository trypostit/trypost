<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChangelogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChangelogEntry>
 */
class ChangelogEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publishedOn = now()->subDay()->toDateString();
        $name = Str::slug(fake()->unique()->words(3, true));

        return [
            'slug' => "{$publishedOn}-{$name}",
            'title' => fake()->sentence(5),
            'tags' => [],
            'body' => fake()->paragraph(),
            'published_on' => $publishedOn,
        ];
    }
}
