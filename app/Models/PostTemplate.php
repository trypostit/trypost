<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PostTemplate\Visibility;
use Database\Factories\PostTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostTemplate extends Model
{
    /** @use HasFactory<PostTemplateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'visibility',
        'emoji',
        'title',
        'description',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
        ];
    }

    /**
     * Templates the user may see: every team template plus their own personal ones.
     *
     * @param  Builder<PostTemplate>  $query
     * @return Builder<PostTemplate>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('visibility', Visibility::Team->value)
            ->orWhere(fn (Builder $query) => $query
                ->where('visibility', Visibility::Personal->value)
                ->where('user_id', $user->id)));
    }

    /**
     * @param  Builder<PostTemplate>  $query
     * @return Builder<PostTemplate>
     */
    public function scopeTeam(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('visibility', Visibility::Team->value));
    }

    /**
     * @param  Builder<PostTemplate>  $query
     * @return Builder<PostTemplate>
     */
    public function scopePersonalFor(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('visibility', Visibility::Personal->value)
            ->where('user_id', $user->id));
    }

    /**
     * @param  Builder<PostTemplate>  $query
     * @return Builder<PostTemplate>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $query->when($search !== null && $search !== '', fn (Builder $builder) => $builder->where(fn (Builder $inner) => $inner
            ->whereLike('title', "%{$search}%")
            ->orWhereLike('description', "%{$search}%")));
    }

    public function isEditableBy(User $user): bool
    {
        return $this->visibility === Visibility::Team || $this->user_id === $user->id;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
