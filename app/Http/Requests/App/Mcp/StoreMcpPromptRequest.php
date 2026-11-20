<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Mcp;

use App\Enums\AccessToken\McpClient;
use App\Enums\Mcp\PromptAnswer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMcpPromptRequest extends FormRequest
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
            'answer' => ['required', Rule::enum(PromptAnswer::class)],
            'client' => [
                Rule::excludeIf(fn (): bool => $this->input('answer') !== PromptAnswer::Connect->value),
                'required',
                Rule::enum(McpClient::class),
            ],
        ];
    }
}
