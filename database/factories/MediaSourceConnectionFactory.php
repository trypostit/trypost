<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Media\Source;
use App\Models\MediaSourceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaSourceConnection>
 */
class MediaSourceConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => Source::Canva,
            'external_user_id' => 'canva-user',
            'external_team_id' => 'canva-team',
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'expires_at' => now()->addHours(4),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
