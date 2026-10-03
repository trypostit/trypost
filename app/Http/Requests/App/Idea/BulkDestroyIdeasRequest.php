<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea;

use App\Models\Idea;
use Illuminate\Foundation\Http\FormRequest;

class BulkDestroyIdeasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Idea::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idea_ids' => ['required', 'array', 'max:'.Idea::MAX_BATCH],
            'idea_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
