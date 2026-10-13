<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\AccessToken\McpClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWelcomeMcpConnectClickRequest extends FormRequest
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
        return [
            'client' => ['required', Rule::enum(McpClient::class)],
        ];
    }
}
