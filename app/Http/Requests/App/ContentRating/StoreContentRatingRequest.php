<?php

declare(strict_types=1);

namespace App\Http\Requests\App\ContentRating;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContentRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->currentWorkspace !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            // Registered morph aliases only: an arbitrary type would blow up
            // with "class not found" when ->rateable() is resolved later.
            'rateable_type' => ['nullable', 'string', 'required_with:rateable_id', Rule::in(array_keys(Relation::morphMap()))],
            'rateable_id' => ['nullable', 'string', 'required_with:rateable_type'],
        ];
    }
}
