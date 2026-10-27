<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'stripe_coupon_id',
        'starts_at',
        'ends_at',
        'max_redemptions',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_redemptions' => 'integer',
        ];
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)->withPivot('claimed_at', 'redeemed_at');
    }

    /**
     * Started, not ended, and with a place left. Only redemptions use up a
     * place; a claim alone never does.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('offers.starts_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('offers.ends_at')->orWhere('offers.ends_at', '>', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('offers.max_redemptions')
                    ->orWhere('offers.max_redemptions', '>', function (QueryBuilder $query): void {
                        $query->selectRaw('count(*)')
                            ->from('account_offer')
                            ->whereColumn('account_offer.offer_id', 'offers.id')
                            ->whereNotNull('account_offer.redeemed_at');
                    });
            });
    }

    /**
     * The available offer with exactly this slug. MySQL compares strings
     * without case, so the match is confirmed here, where both engines agree.
     */
    public static function findAvailable(string $slug): ?self
    {
        $offer = self::available()->firstWhere('slug', $slug);

        return $offer?->slug === $slug ? $offer : null;
    }

    /**
     * The key of the offer's copy in lang/<locale>/offers.php.
     */
    public function langKey(): string
    {
        return Str::replace('-', '_', $this->slug);
    }
}
