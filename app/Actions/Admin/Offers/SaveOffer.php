<?php

declare(strict_types=1);

namespace App\Actions\Admin\Offers;

use App\Actions\Admin\RecordAdminAction;
use App\Enums\Admin\AdminActionType;
use App\Models\Offer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SaveOffer
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Creates an offer, or updates one, from the backoffice form. Its days
     * are the admin's own: it starts at the start of its first day and ends
     * at the end of its last, in their zone.
     *
     * @param  array{slug?: string, stripe_coupon_id: ?string, trial_days: ?int, starts_at: string, ends_at: ?string, max_redemptions: ?int, allow_promotion_codes: bool, first_subscription_only: bool, show_banner: bool, requires_card: bool}  $data
     */
    public function execute(User $admin, array $data, ?Offer $offer = null): Offer
    {
        $timezone = (string) $admin->timezone;
        $attributes = [
            ...collect($data)->except(['slug', 'starts_at', 'ends_at'])->all(),
            'starts_at' => CarbonImmutable::parse($data['starts_at'], $timezone)->startOfDay()->utc(),
            'ends_at' => transform($data['ends_at'], fn (string $day): CarbonImmutable => CarbonImmutable::parse($day, $timezone)->endOfDay()->utc()),
        ];

        if ($offer === null) {
            $offer = Offer::query()->create([...$attributes, 'slug' => (string) data_get($data, 'slug')]);
            $this->recordAdminAction->execute($admin, AdminActionType::CreateOffer, $offer, ['slug' => $offer->slug]);

            return $offer;
        }

        $offer->update([
            ...$attributes,
            'starts_at' => self::keepIfSameDay($offer->starts_at, $attributes['starts_at'], $timezone),
            'ends_at' => self::keepIfSameDay($offer->ends_at, $attributes['ends_at'], $timezone),
        ]);
        $this->recordAdminAction->execute($admin, AdminActionType::UpdateOffer, $offer, ['slug' => $offer->slug, 'changes' => $offer->getChanges()]);

        return $offer;
    }

    /**
     * The stored moment when the form kept its day: the form only knows days,
     * so saving an untouched date must not move an offer that starts or ends
     * at another hour.
     */
    private static function keepIfSameDay(?CarbonInterface $stored, ?CarbonImmutable $submitted, string $timezone): ?CarbonInterface
    {
        if ($stored === null || $submitted === null) {
            return $submitted;
        }

        if ($stored->copy()->setTimezone($timezone)->toDateString() === $submitted->setTimezone($timezone)->toDateString()) {
            return $stored;
        }

        return $submitted;
    }
}
