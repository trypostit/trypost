<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Token;

class AccessToken extends Token
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'user_id',
        'client_id',
        'workspace_id',
        'name',
        'scopes',
        'revoked',
        'expires_at',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'json',
            'revoked' => 'bool',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Passport resolves the user model via the OAuth client's provider, which
     * breaks eager-loading `user` (the relation is built on an empty token with
     * no client). Tokens in TryPost always belong to App\Models\User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Active OAuth grants used by MCP clients (excludes personal access API keys).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveMcpOAuth(Builder $query): Builder
    {
        return $query
            ->mcpOAuth()
            ->where('revoked', false)
            ->where(function (Builder $expires): void {
                $expires->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMcpOAuth(Builder $query): Builder
    {
        return $query
            ->whereJsonContains('scopes', 'mcp:use')
            ->whereHas(
                'client',
                fn (Builder $client): Builder => $client
                    ->where('revoked', false)
                    ->whereJsonDoesntContain('grant_types', 'personal_access'),
            );
    }

    /**
     * Whether this token was issued by a live personal-access client (REST API keys).
     */
    public function isPersonalAccessToken(): bool
    {
        $this->loadMissing('client');

        return $this->client !== null
            && ! $this->client->revoked
            && $this->client->hasGrantType('personal_access');
    }

    /**
     * Whether this is a non-revoked, unexpired MCP OAuth grant with mcp:use.
     */
    public function isActiveMcpGrant(): bool
    {
        $this->loadMissing('client');

        if ($this->revoked) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if (! in_array('mcp:use', $this->scopes ?? [], true)) {
            return false;
        }

        return $this->client !== null
            && ! $this->client->revoked
            && ! $this->client->hasGrantType('personal_access');
    }

    /**
     * Whether this MCP grant can actually use the product (active token + a
     * workspace the owner can create posts in).
     */
    public function isUsableMcpGrant(?User $user = null, ?Workspace $workspace = null): bool
    {
        if (! $this->isActiveMcpGrant()) {
            return false;
        }

        $user ??= User::query()
            ->with('currentWorkspace')
            ->find($this->user_id);

        if (! $user instanceof User) {
            return false;
        }

        $workspace ??= $this->workspace ?? $user->currentWorkspace;

        return $workspace instanceof Workspace
            && $user->can('createPost', $workspace);
    }
}
