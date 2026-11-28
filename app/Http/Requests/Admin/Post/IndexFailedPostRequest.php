<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Post;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Support\Admin\AdminNetworks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexFailedPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'networks' => ['nullable', 'array'],
            'networks.*' => ['string', Rule::in(AdminNetworks::options())],
            'category' => ['nullable', Rule::enum(ErrorCategory::class)],
        ];
    }

    /**
     * @return list<string>
     */
    public function networks(): array
    {
        return collect($this->validated('networks', []))->values()->all();
    }

    public function category(): ?ErrorCategory
    {
        return $this->enum('category', ErrorCategory::class);
    }

    /**
     * @return list<Platform>
     */
    public function platforms(): array
    {
        return AdminNetworks::platformsFor($this->networks());
    }
}
