<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Workspace\ImageStyle;
use App\Models\Traits\HasMedia;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasMedia, HasUuids;

    protected $fillable = [
        'account_id',
        'user_id',
        'name',
        'brand_website',
        'brand_description',
        'brand_voice_traits',
        'brand_color',
        'background_color',
        'text_color',
        'brand_font',
        'image_style',
        'content_language',
    ];

    protected function casts(): array
    {
        return [
            'image_style' => ImageStyle::class,
            'brand_voice_traits' => 'array',
        ];
    }

    protected $appends = ['has_logo', 'logo_url'];

    public function getHasLogoAttribute(): bool
    {
        return $this->getFirstMedia('logo') !== null;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(WorkspaceSignature::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(WorkspaceLabel::class);
    }

    /**
     * Get invites for this workspace (invites from the same account that include this workspace).
     *
     * @return Collection<int, Invite>
     */
    public function invites()
    {
        return Invite::where('account_id', $this->account_id)
            ->whereJsonContains('workspaces', $this->id)
            ->whereNull('accepted_at');
    }

    public function hasMember(User $user): bool
    {
        return $this->account?->owner_id === $user->id || $this->members()->where('user_id', $user->id)->exists();
    }
}
