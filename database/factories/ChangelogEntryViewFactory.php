<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangelogEntryView>
 */
class ChangelogEntryViewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'changelog_entry_id' => ChangelogEntry::factory(),
            'user_id' => User::factory(),
            'viewed_at' => now(),
        ];
    }
}
