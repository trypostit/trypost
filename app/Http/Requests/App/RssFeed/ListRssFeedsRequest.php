<?php

declare(strict_types=1);

namespace App\Http\Requests\App\RssFeed;

use App\Models\RssFeed;
use Illuminate\Foundation\Http\FormRequest;

class ListRssFeedsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', RssFeed::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
