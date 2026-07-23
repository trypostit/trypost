<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Onboarding;

use App\Enums\User\ReferralSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnboardingReferralSourceRequest extends FormRequest
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
        return [
            'referral_source' => ['required', Rule::enum(ReferralSource::class)],
        ];
    }
}
