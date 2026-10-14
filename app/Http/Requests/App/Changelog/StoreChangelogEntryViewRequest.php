<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Changelog;

use Illuminate\Foundation\Http\FormRequest;

class StoreChangelogEntryViewRequest extends FormRequest
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
}
