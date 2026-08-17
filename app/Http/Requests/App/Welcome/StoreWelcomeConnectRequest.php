<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\SocialAccount\Status;
use App\Enums\User\Goal;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWelcomeConnectRequest extends FormRequest
{
    /**
     * @var list<string>|null
     */
    private ?array $connectedPlatforms = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function connectedPlatforms(): array
    {
        return $this->connectedPlatforms ??= $this->user()->currentWorkspace->socialAccounts()
            ->where('status', Status::Connected)
            ->orderBy('id')
            ->get()
            ->map(fn (SocialAccount $account): string => $account->platform->value)
            ->unique()
            ->values()
            ->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user->currentWorkspace === null) {
                return;
            }

            if (! $user->persona || ! $this->hasCurrentGoals($user) || ! $user->referral_source) {
                return;
            }

            if ($this->connectedPlatforms() === []) {
                $validator->errors()->add('connect', __('welcome.connect.required'));
            }
        });
    }

    /**
     * Mirrors WelcomeController::hasCurrentGoals — dropped enum values
     * must not count as a completed goals step.
     */
    private function hasCurrentGoals(User $user): bool
    {
        $goals = $user->goals;

        if (! is_array($goals) || $goals === []) {
            return false;
        }

        $allowed = array_map(fn (Goal $goal): string => $goal->value, Goal::cases());

        return array_intersect($goals, $allowed) !== [];
    }
}
