<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\SocialAccount\Status;
use App\Enums\User\Goal;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreWelcomeConnectRequest extends FormRequest
{
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
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if ($user === null || $user->account?->hasAppAccess() || ! $user->isAccountOwner()) {
                    return;
                }

                if (! $this->hasCompletedPriorWelcomeSteps($user)) {
                    return;
                }

                if ($this->hasConnectedSocialAccount()) {
                    return;
                }

                $validator->errors()->add('connect', __('welcome.connect.required'));
            },
        ];
    }

    private function hasCompletedPriorWelcomeSteps(User $user): bool
    {
        if (! $user->persona) {
            return false;
        }

        if (! $this->hasCurrentGoals($user)) {
            return false;
        }

        return $user->referral_source !== null;
    }

    private function hasCurrentGoals(User $user): bool
    {
        $goals = $user->goals;

        if (! is_array($goals) || $goals === []) {
            return false;
        }

        $allowed = array_map(fn (Goal $goal): string => $goal->value, Goal::cases());

        return array_intersect($goals, $allowed) !== [];
    }

    private function hasConnectedSocialAccount(): bool
    {
        $workspace = $this->user()?->currentWorkspace;

        if ($workspace === null) {
            return false;
        }

        return $workspace->socialAccounts()
            ->where('status', Status::Connected)
            ->exists();
    }
}
