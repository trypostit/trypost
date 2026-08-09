<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Ai\DraftStatus;
use App\Models\AiPostDraft;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPostDraft>
 */
class AiPostDraftFactory extends Factory
{
    protected $model = AiPostDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'social_account_id' => null,
            'format' => 'instagram_carousel',
            'template' => 'image_card',
            'image_count' => 3,
            'apply_brand_visuals' => true,
            'scheduled_date' => null,
            'prompt' => $this->faker->sentence(),
            'structured' => null,
            'status' => DraftStatus::Preparing,
            'post_id' => null,
            'error' => null,
        ];
    }

    public function ready(array $structured): static
    {
        return $this->state(fn () => [
            'status' => DraftStatus::Ready,
            'structured' => $structured,
        ]);
    }
}
