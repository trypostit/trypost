<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Plan\Slug;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'stripe_monthly_price_id',
        'stripe_yearly_price_id',
        'monthly_credits_limit',
        'workspace_limit',
        'sort',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'slug' => Slug::class,
            'is_archived' => 'boolean',
            'monthly_credits_limit' => 'integer',
            'workspace_limit' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }
}
