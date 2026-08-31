<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Ai;

use App\Enums\Ai\ContentStyle;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
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
            'creation_id' => ['required', 'string', 'uuid'],
            'format' => [
                'required',
                'string',
                Rule::in($allowedFormats),
            ],
            'social_account_id' => ['nullable', 'uuid'],
            'google_business_profile_location_id' => ['nullable', 'uuid'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:10'],
            'prompt' => AiPromptRules::wizardPromptRule(),
            'date' => ['nullable', 'date_format:Y-m-d'],
            'template' => ['sometimes', 'string', Rule::enum(ContentStyle::class)],
            'apply_brand_visuals' => ['sometimes', 'boolean'],
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

            $isGoogleBusinessProfile = $this->input('format') === ContentType::GoogleBusinessProfileStandard->value;
            $socialAccountId = $this->input('social_account_id');
            $locationId = $this->input('google_business_profile_location_id');

            if ($validator->errors()->hasAny([
                'format',
                'social_account_id',
                'google_business_profile_location_id',
            ])) {
                return;
            }

            if (! $isGoogleBusinessProfile) {
                if (filled($locationId)) {
                    $validator->errors()->add(
                        'google_business_profile_location_id',
                        'A Google Business Profile location can only be used with a Google Business Profile format.',
                    );
                }

                return;
            }

            if (blank($socialAccountId)) {
                $validator->errors()->add(
                    'social_account_id',
                    trans('validation.required', ['attribute' => 'social account']),
                );
            }

            if (blank($locationId)) {
                $validator->errors()->add(
                    'google_business_profile_location_id',
                    'Choose a Google Business Profile location.',
                );

                return;
            }

            $account = $this->user()?->currentWorkspace
                ?->socialAccounts()
                ?->active()
                ?->whereKey($socialAccountId)
                ?->first();

            $validLocation = $account?->platform === Platform::GoogleBusinessProfile
                && $account->googleBusinessProfileLocations()
                    ->whereKey($locationId)
                    ->where('is_selected', true)
                    ->exists();

            if (! $validLocation) {
                $validator->errors()->add(
                    'google_business_profile_location_id',
                    'Choose a selected Google Business Profile location managed by this connection.',
                );
            }
        });
    }
}
