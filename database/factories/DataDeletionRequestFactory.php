<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DataDeletionRequest\Status;
use App\Enums\SocialAccount\MetaApp;
use App\Models\DataDeletionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DataDeletionRequest>
 */
class DataDeletionRequestFactory extends Factory
{
    protected $model = DataDeletionRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app' => MetaApp::Facebook,
            'confirmation_code' => Str::lower(Str::random(32)),
            'status' => Status::Pending,
            'channels_affected' => 0,
        ];
    }

    public function completed(int $channelsAffected = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Status::Completed,
            'channels_affected' => $channelsAffected,
            'completed_at' => now(),
        ]);
    }
}
