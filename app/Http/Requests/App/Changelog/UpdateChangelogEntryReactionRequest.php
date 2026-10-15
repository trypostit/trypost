<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Changelog;

use App\Enums\Changelog\Reaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChangelogEntryReactionRequest extends FormRequest
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
            'reaction' => ['present', 'nullable', Rule::enum(Reaction::class)],
        ];
    }
}
