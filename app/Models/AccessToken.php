<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserWorkspace\Role;
use App\Observers\AccessTokenObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Token;

#[ObservedBy(AccessTokenObserver::class)]
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
     * Bound, active MCP OAuth grant that counts toward account onboarding:
     * workspace belongs to the account, and the token owner can create posts there.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOnboardingMcpConnection(Builder $query, Account $account): Builder
    {
        $createPostRoles = [Role::Admin->value, Role::Member->value];

        return $query
            ->activeMcpOAuth()
            ->whereNotNull('workspace_id')
            ->whereIn(
                'user_id',
                User::query()->select('id')->where('account_id', $account->id),
            )
            ->whereHas(
                'workspace',
                fn (Builder $workspace): Builder => $workspace->where('account_id', $account->id),
            )
            ->where(function (Builder $tokens) use ($account, $createPostRoles): void {
                $tokens->where('user_id', $account->owner_id)
                    ->orWhereExists(function ($sub) use ($createPostRoles): void {
                        $sub->selectRaw('1')
                            ->from('user_workspace')
                            ->whereColumn('user_workspace.user_id', 'oauth_access_tokens.user_id')
                            ->whereColumn('user_workspace.workspace_id', 'oauth_access_tokens.workspace_id')
                            ->whereIn('user_workspace.role', $createPostRoles);
                    });
            });
    }

    /**
     * MCP OAuth grants that still represent a live or recoverable session
     * (unexpired access token, or expired access with a live refresh token).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeConnectedMcpOAuth(Builder $query): Builder
    {
        return $query
            ->mcpOAuth()
            ->where('revoked', false)
            ->where(function (Builder $alive): void {
                $alive
                    ->where(function (Builder $expires): void {
                        $expires->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->orWhereHas(
                        'refreshToken',
                        fn (Builder $refresh): Builder => $refresh
                            ->where('revoked', false)
                            ->where(function (Builder $refreshExpires): void {
                                $refreshExpires->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', now());
                            }),
                    );
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
     * Personal-access API keys (REST), excluding MCP OAuth clients.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePersonalAccessApiKey(Builder $query): Builder
    {
        return $query->whereHas(
            'client',
            fn (Builder $client): Builder => $client
                ->where('revoked', false)
                ->whereJsonContains('grant_types', 'personal_access'),
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
     * Whether this is a non-revoked MCP OAuth grant with mcp:use (ignores expiry).
     */
    public function isMcpOAuthGrant(): bool
    {
        $this->loadMissing('client');

        if ($this->revoked) {
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
     * Whether this is a non-revoked, unexpired MCP OAuth grant with mcp:use.
     */
    public function isActiveMcpGrant(): bool
    {
        if (! $this->isMcpOAuthGrant()) {
            return false;
        }

        return $this->expires_at === null || ! $this->expires_at->isPast();
    }

    /**
     * Whether a refresh token can still mint a new access token for this grant.
     */
    public function hasLiveRefreshToken(): bool
    {
        $this->loadMissing('refreshToken');

        $refresh = $this->refreshToken;

        if ($refresh === null || $refresh->revoked) {
            return false;
        }

        return $refresh->expires_at === null || $refresh->expires_at->isFuture();
    }

    /**
     * Whether this MCP grant can actually use the product (active token + a
     * workspace the owner can view — write tools enforce createPost themselves).
     */
    public function isUsableMcpGrant(?User $user = null, ?Workspace $workspace = null): bool
    {
        if (! $this->isActiveMcpGrant()) {
            return false;
        }

        return $this->ownerCanViewWorkspace($user, $workspace);
    }

    /**
     * Whether this MCP grant should appear in the connected-clients list
     * (usable now, or recoverable via refresh, for a user who can view a workspace).
     */
    public function isListedMcpConnection(?User $user = null, ?Workspace $workspace = null): bool
    {
        if (! $this->isMcpOAuthGrant()) {
            return false;
        }

        if (! $this->isActiveMcpGrant() && ! $this->hasLiveRefreshToken()) {
            return false;
        }

        return $this->ownerCanViewWorkspace($user, $workspace);
    }

    private function ownerCanViewWorkspace(?User $user = null, ?Workspace $workspace = null): bool
    {
        $user ??= User::query()
            ->with('currentWorkspace')
            ->find($this->user_id);

        if (! $user instanceof User) {
            return false;
        }

        // Bound grants resolve only from the token — never the switcher.
        $workspace ??= $this->workspace;

        return $workspace instanceof Workspace
            && $user->can('view', $workspace);
    }
}
