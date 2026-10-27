<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'stripe_coupon_id',
        'trial_days',
        'allow_promotion_codes',
        'starts_at',
        'ends_at',
        'max_redemptions',
        'first_subscription_only',
        'show_banner',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_days' => 'integer',
            'allow_promotion_codes' => 'boolean',
            'max_redemptions' => 'integer',
            'first_subscription_only' => 'boolean',
            'show_banner' => 'boolean',
        ];
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)->withPivot('claimed_at', 'redeemed_at');
    }

    /**
     * The accounts that redeemed the offer. Each one uses up a place.
     */
    public function redemptions(): BelongsToMany
    {
        return $this->accounts()->wherePivotNotNull('redeemed_at');
    }

    /**
     * Started, not ended, and with a place left. Only redemptions use up a
     * place; a claim alone never does.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->whereNowOrPast('offers.starts_at')
            ->where(fn (Builder $query): Builder => $query->whereNull('offers.ends_at')->orWhereFuture('offers.ends_at'))
            ->where(fn (Builder $query): Builder => $query->whereNull('offers.max_redemptions')->orHas('redemptions', '<', DB::raw('offers.max_redemptions')));
    }

    /**
     * Available offers the account may still use: never one it redeemed, and
     * a first-subscription offer only while it has never been billed.
     */
    public function scopeAvailableTo(Builder $query, Account $account): Builder
    {
        return $query
            ->available()
            ->whereDoesntHave('redemptions', fn (Builder $query): Builder => $query->whereKey($account->id))
            ->when($account->hasHadBillableSubscription(), fn (Builder $query): Builder => $query->where('offers.first_subscription_only', false));
    }

    /**
     * The available offer with exactly this slug. MySQL compares strings
     * without case, so the match is confirmed here, where both engines agree.
     */
    public static function findAvailable(string $slug): ?self
    {
        return self::available()->where('slug', $slug)->get()->firstWhere('slug', $slug);
    }

    /**
     * The key of the offer's copy in lang/<locale>/offers.php.
     */
    public function langKey(): string
    {
        return Str::replace('-', '_', $this->slug);
    }
}
