<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Ai;

use App\Enums\Ai\ContentStyle;
use App\Enums\PostPlatform\ContentType;
use App\Support\AiPromptRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StartPostCreationRequest extends FormRequest
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
        $allowedFormats = array_map(fn (ContentType $t) => $t->value, ContentType::aiSupported());
        $allowedFormats[] = ContentType::CAROUSEL_FORMAT;

        return [
            'format' => [
                'required',
                'string',
                Rule::in($allowedFormats),
            ],
            'social_account_id' => ['nullable', 'uuid'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:10'],
            'prompt' => AiPromptRules::wizardPromptRule(),
            'date' => ['nullable', 'date_format:Y-m-d'],
            'template' => ['sometimes', 'string', Rule::enum(ContentStyle::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $style = ContentStyle::tryFrom((string) $this->input('template', ContentStyle::default()->value))
                ?? ContentStyle::default();

            if ($style->needsAccount() && blank($this->input('social_account_id'))) {
                $validator->errors()->add('social_account_id', trans('validation.required', ['attribute' => 'social account']));
            }
        });
    }
}
