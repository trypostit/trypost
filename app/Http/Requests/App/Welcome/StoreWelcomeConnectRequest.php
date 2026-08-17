<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\SocialAccount\Status;
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

                if ($this->hasConnectedSocialAccount()) {
                    return;
                }

                $validator->errors()->add('connect', __('welcome.connect.required'));
            },
        ];
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
