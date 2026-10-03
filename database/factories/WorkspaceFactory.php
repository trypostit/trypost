<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
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
            'name' => fake()->company(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Workspace $workspace) {
            if (! $workspace->account_id) {
                if ($workspace->user_id) {
                    $user = User::find($workspace->user_id);

                    if ($user?->account_id) {
                        $workspace->account_id = $user->account_id;

                        return;
                    }
                }

                $workspace->account_id = Account::factory()->create()->id;
            }
        });
    }
}
