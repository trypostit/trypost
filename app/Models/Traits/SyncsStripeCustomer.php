<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Jobs\Billing\SyncStripeCustomer;
use App\Support\AttributionKeys;
use BackedEnum;
use Illuminate\Support\Str;

/**
 * Keeps the owner's attribution and Welcome answers on the account's Stripe
 * customer, so they survive an abandoned Checkout.
 */
trait SyncsStripeCustomer
{
    private const array STRIPE_METADATA_ATTRIBUTES = [
        ...AttributionKeys::UTM,
        ...AttributionKeys::CLICK_ID,
        'persona',
        'goals',
        'previous_software',
        'referral_source',
    ];

    public static function bootSyncsStripeCustomer(): void
    {
        static::updated(function (self $user): void {
            if (! $user->wasChanged(['name', 'email', ...self::STRIPE_METADATA_ATTRIBUTES])) {
                return;
            }

            SyncStripeCustomer::dispatch($user->id);
        });
    }

    /**
     * Stripe rejects metadata values longer than 500 characters:
     * https://docs.stripe.com/api/metadata
     *
     * @return array<string, string>
     */
    public function stripeMetadata(): array
    {
        return collect($this->only(self::STRIPE_METADATA_ATTRIBUTES))
            ->map(fn (mixed $value): string => match (true) {
                $value instanceof BackedEnum => (string) $value->value,
                is_array($value) => implode(',', $value),
                default => (string) $value,
            })
            ->filter(filled(...))
            ->map(fn (string $value): string => Str::limit($value, 500, ''))
            ->all();
    }

    /**
     * Stripe merges metadata on update, so a cleared answer has to be sent as
     * an empty string to remove its key from the customer.
     *
     * @return array<string, string>
     */
    public function stripeMetadataForUpdate(): array
    {
        return [
            ...array_fill_keys(self::STRIPE_METADATA_ATTRIBUTES, ''),
            ...$this->stripeMetadata(),
        ];
    }
}
