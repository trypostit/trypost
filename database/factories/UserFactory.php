<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\User\DefaultPostAction;
use App\Enums\User\Locale;
use App\Enums\User\Theme;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Account;
use App\Models\User;
use App\Support\Timezone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'google_id' => null,
            'github_id' => null,
            'remember_token' => Str::random(10),
            'account_id' => Account::factory(),
            'current_workspace_id' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_term' => null,
            'utm_content' => null,
            'registration_ip' => null,
            'persona' => null,
            'locale' => Locale::DEFAULT,
            'timezone' => Timezone::DEFAULT,
            'theme' => Theme::DEFAULT,
            'time_format' => TimeFormat::DEFAULT,
            'week_starts_on' => WeekStart::DEFAULT,
            'default_post_action' => DefaultPostAction::DEFAULT,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->account_id && ! $user->account?->owner_id) {
                $user->account->update(['owner_id' => $user->id]);
            }
        });
    }

    public function theme(Theme $theme): static
    {
        return $this->state(fn (array $attributes) => ['theme' => $theme]);
    }

    public function timeFormat(TimeFormat $timeFormat): static
    {
        return $this->state(fn (array $attributes) => ['time_format' => $timeFormat]);
    }

    public function weekStartsOn(WeekStart $weekStart): static
    {
        return $this->state(fn (array $attributes) => ['week_starts_on' => $weekStart]);
    }

    public function defaultPostAction(DefaultPostAction $action): static
    {
        return $this->state(fn (array $attributes) => ['default_post_action' => $action]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
