<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\SocialAccount\Status;
use App\Enums\User\Goal;
use App\Models\SocialAccount;
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

            if ($user->account?->hasAppAccess() || ! $user->isAccountOwner()) {
                return;
            }

            if (! $user->persona || ! Goal::containsCurrent($user->goals) || ! $user->referral_source) {
                return;
            }

            if ($this->connectedPlatforms() === []) {
                $validator->errors()->add('connect', __('welcome.connect.required'));
            }
        });
    }
}
