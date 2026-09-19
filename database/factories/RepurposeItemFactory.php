<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Repurpose\ItemStatus;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepurposeItem>
 */
class RepurposeItemFactory extends Factory
{
    protected $model = RepurposeItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repurpose_id' => Repurpose::factory(),
            'source_media_id' => fake()->uuid(),
            'source_permalink' => fake()->url(),
            'source_created_at' => now()->subMinutes(10),
            'status' => ItemStatus::Pending,
        ];
    }
}
