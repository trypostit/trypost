<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Workspace;

use App\Enums\Workspace\BrandFont;
use App\Enums\Workspace\BrandVoiceTrait;
use App\Enums\Workspace\ContentLanguage;
use App\Enums\Workspace\ImageStyle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'brand_website' => ['nullable', 'url', 'max:255'],
            'brand_description' => ['nullable', 'string', 'max:2000'],
            'brand_voice_traits' => ['nullable', 'array'],
            'brand_voice_traits.*' => ['string', Rule::enum(BrandVoiceTrait::class)],
            'brand_color' => $hex,
            'background_color' => $hex,
            'text_color' => $hex,
            'brand_font' => ['sometimes', 'required', 'string', Rule::in(BrandFont::values())],
            'image_style' => ['sometimes', 'required', 'string', Rule::in(ImageStyle::values())],
            'content_language' => ['sometimes', 'string', Rule::in(ContentLanguage::values())],
            'logo_url' => ['nullable', 'url', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The workspace name is required.',
            'name.max' => 'The workspace name must be at most 255 characters.',
        ];
    }
}
