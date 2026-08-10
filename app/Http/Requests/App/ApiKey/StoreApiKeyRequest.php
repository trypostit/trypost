<?php

declare(strict_types=1);

namespace App\Http\Requests\App\ApiKey;

use App\Actions\ApiKey\CreateApiKey;
use Illuminate\Foundation\Http\FormRequest;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workspace = $user?->currentWorkspace;

        return $workspace !== null
            && $user->can('manageTeam', $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => CreateApiKey::expiresAtRules(),
        ];
    }
}
