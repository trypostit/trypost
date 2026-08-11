<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserWorkspace\Role as WorkspaceRole;
use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invite extends Model
{
    /** @use HasFactory<InviteFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'invited_by',
        'email',
        'role',
        'workspaces',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'workspaces' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * `id` is a native Postgres uuid column — find() on a non-UUID string
     * raises a type-cast error rather than returning null.
     */
    public static function fromId(?string $id): ?self
    {
        if (! $id || ! Str::isUuid($id)) {
            return null;
        }

        return self::find($id);
    }
}
