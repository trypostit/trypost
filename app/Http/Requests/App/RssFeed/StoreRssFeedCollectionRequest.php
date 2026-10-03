<?php

declare(strict_types=1);

namespace App\Http\Requests\App\RssFeed;

use App\Models\RssFeedCollection;
use Illuminate\Foundation\Http\FormRequest;

class StoreRssFeedCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RssFeedCollection::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }
}
