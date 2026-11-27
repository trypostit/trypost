<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Offer;

use App\Rules\StripeCouponExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOfferRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:offers,slug'],
            'stripe_coupon_id' => ['nullable', 'string', 'max:255', 'prohibited_if_declined:requires_card', new StripeCouponExists],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365', 'required_if_declined:requires_card'],
            'starts_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:2037-12-31'],
            'ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_at', 'before_or_equal:2037-12-31'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'allow_promotion_codes' => ['required', 'boolean'],
            'first_subscription_only' => ['required', 'boolean'],
            'show_banner' => ['required', 'boolean'],
            'requires_card' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and dashes, like bf26 or free-trial-14-days.',
            'stripe_coupon_id.prohibited_if_declined' => 'A trial without a card never opens Stripe, so it cannot carry a coupon.',
            'trial_days.required_if_declined' => 'A trial without a card needs its number of days.',
            'starts_at.before_or_equal' => 'An offer must start by December 31, 2037.',
            'ends_at.before_or_equal' => 'An offer must end by December 31, 2037.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (filled($this->input('stripe_coupon_id')) && $this->boolean('allow_promotion_codes')) {
                    $validator->errors()->add('allow_promotion_codes', 'Stripe takes a coupon or the promotion code field, not both: the coupon wins.');
                }

                if (blank($this->input('stripe_coupon_id')) && (int) $this->input('trial_days') === 0 && ! $this->boolean('allow_promotion_codes')) {
                    $validator->errors()->add('stripe_coupon_id', 'An offer needs a coupon, a trial or the promotion code field.');
                }
            },
        ];
    }

    /**
     * The offer as the form sent it, typed: a blank coupon, trial or limit is none.
     *
     * @return array{slug: string, stripe_coupon_id: ?string, trial_days: ?int, starts_at: string, ends_at: ?string, max_redemptions: ?int, allow_promotion_codes: bool, first_subscription_only: bool, show_banner: bool, requires_card: bool}
     */
    public function offer(): array
    {
        return [
            'slug' => $this->string('slug')->toString(),
            'stripe_coupon_id' => $this->filled('stripe_coupon_id') ? $this->string('stripe_coupon_id')->toString() : null,
            'trial_days' => $this->filled('trial_days') ? $this->integer('trial_days') : null,
            'starts_at' => $this->string('starts_at')->toString(),
            'ends_at' => $this->filled('ends_at') ? $this->string('ends_at')->toString() : null,
            'max_redemptions' => $this->filled('max_redemptions') ? $this->integer('max_redemptions') : null,
            'allow_promotion_codes' => $this->boolean('allow_promotion_codes'),
            'first_subscription_only' => $this->boolean('first_subscription_only'),
            'show_banner' => $this->boolean('show_banner'),
            'requires_card' => $this->boolean('requires_card'),
        ];
    }
}
