<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasMedia;
use App\Observers\WorkspaceObserver;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([WorkspaceObserver::class])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasMedia, HasUuids;

    protected $fillable = [
        'account_id',
        'user_id',
        'name',
    ];

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
            ->withPivot(['is_admin', 'requires_approval'])
            ->withTimestamps();
    }

    /**
     * Users who may approve posts here: the account owner and every member who publishes directly.
     *
     * @return Collection<int, User>
     */
    public function approvers(): Collection
    {
        $approvers = $this->members()
            ->with('account')
            ->get()
            ->filter(fn (User $member): bool => $member->publishesDirectlyThrough($this, $member->pivot));
        $owner = $this->account?->owner;

        if ($owner !== null && ! $approvers->contains('id', $owner->id)) {
            $approvers->push($owner);
        }

        return $approvers->values();
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

    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class);
    }

    public function postTemplates(): HasMany
    {
        return $this->hasMany(PostTemplate::class);
    }

    public function ideaStages(): HasMany
    {
        return $this->hasMany(IdeaStage::class)->orderBy('position');
    }

    public function rssFeeds(): HasMany
    {
        return $this->hasMany(RssFeed::class);
    }

    public function rssFeedCollections(): HasMany
    {
        return $this->hasMany(RssFeedCollection::class)->orderBy('position');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function repurposes(): HasMany
    {
        return $this->hasMany(Repurpose::class);
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
