<?php

declare(strict_types=1);

namespace App\Http\Requests\App\RssFeed;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRssFeedCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('rssFeedCollection'));
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
