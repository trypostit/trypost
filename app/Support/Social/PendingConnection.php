<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A channel connection between the network's consent screen and "Finish
 * connection": the workspace, the card being reconnected, where to return, and
 * the identities the login offered with their tokens. It lives in the
 * server-side session only; the confirmation page never receives the tokens.
 *
 * @phpstan-type Identity array{key: string, platform: string, platform_user_id: string, name: ?string, username: ?string, avatar: ?string, type: string, keeps_avatar: bool, attributes: array<string, mixed>}
 */
final class PendingConnection
{
    public const string SESSION_KEY = 'social_connect';

    public const int TTL_MINUTES = 15;

    private const string DEFAULT_RETURN_ROUTE = 'app.workspace.channels';

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private array $data) {}

    public static function start(Platform $platform, Workspace $workspace, ?string $reconnectId, ?string $returnPath, bool $switchingAccount = false): self
    {
        $pending = new self([
            'platform' => $platform->value,
            'workspace_id' => $workspace->id,
            'reconnect_id' => $reconnectId,
            'switching_account' => $switchingAccount,
            'return' => self::returnTarget($returnPath),
            'identities' => null,
            'expires_at' => null,
            'failure' => null,
        ]);

        $pending->save();

        return $pending;
    }

    public static function current(): ?self
    {
        $data = Session::get(self::SESSION_KEY);

        return is_array($data) ? new self($data) : null;
    }

    public static function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    public function platform(): ?Platform
    {
        return Platform::tryFrom((string) data_get($this->data, 'platform'));
    }

    public function workspaceId(): ?string
    {
        return data_get($this->data, 'workspace_id');
    }

    public function reconnectId(): ?string
    {
        return data_get($this->data, 'reconnect_id');
    }

    /**
     * Whether this connection restarted from "Switch account", so the network
     * is asked to show its login or account chooser again.
     */
    public function isSwitchingAccount(): bool
    {
        return data_get($this->data, 'switching_account') === true;
    }

    /**
     * @param  list<Identity>  $identities
     */
    public function offer(array $identities): void
    {
        $this->data['identities'] = array_values($identities);
        $this->data['expires_at'] = now()->addMinutes(self::TTL_MINUTES)->getTimestamp();
        $this->data['failure'] = null;

        $this->save();
    }

    public function fail(string $reason): void
    {
        $this->data['identities'] = null;
        $this->data['expires_at'] = null;
        $this->data['failure'] = $reason;

        $this->save();
    }

    public function failure(): ?string
    {
        return data_get($this->data, 'failure');
    }

    public function isReady(): bool
    {
        return is_array(data_get($this->data, 'identities'))
            && (int) data_get($this->data, 'expires_at') > now()->getTimestamp();
    }

    /**
     * @return list<Identity>
     */
    public function identities(): array
    {
        return $this->isReady() ? data_get($this->data, 'identities') : [];
    }

    /**
     * @return list<string>
     */
    public function identityKeys(): array
    {
        return array_column($this->identities(), 'key');
    }

    /**
     * Offered identities already connected in the workspace, which may not be
     * picked again. A reconnect locks none: refreshing that card is the point.
     *
     * @return list<string>
     */
    public function lockedIdentityKeys(): array
    {
        $platform = $this->platform();
        $keys = $this->identityKeys();

        if ($this->reconnectId() !== null || $platform === null || $keys === []) {
            return [];
        }

        $connected = SocialAccount::query()
            ->where('workspace_id', $this->workspaceId())
            ->whereIn('platform', $platform->networkPlatformValues())
            ->get(['platform', 'platform_user_id'])
            ->map(fn (SocialAccount $account): string => "{$account->platform->value}:{$account->platform_user_id}");

        return array_values(array_filter($keys, fn (string $key): bool => $connected->contains($key)));
    }

    /**
     * @param  array<int, string>  $keys
     * @return list<Identity>
     */
    public function selected(array $keys): array
    {
        return array_values(array_filter(
            $this->identities(),
            fn (array $identity): bool => in_array(data_get($identity, 'key'), $keys, true),
        ));
    }

    /**
     * The route name of the page the flow returns to.
     */
    public function returnRouteName(): string
    {
        return (string) data_get($this->data, 'return.name', self::DEFAULT_RETURN_ROUTE);
    }

    public function returnUrl(): string
    {
        return self::urlFor(data_get($this->data, 'return'));
    }

    /**
     * The path the flow returns to, for a retry that must come back to the same place.
     */
    public function returnPath(): string
    {
        return (string) parse_url($this->returnUrl(), PHP_URL_PATH);
    }

    public static function defaultReturnUrl(): string
    {
        return route(self::DEFAULT_RETURN_ROUTE);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Identity
     */
    public static function identity(
        Platform $platform,
        string $platformUserId,
        ?string $name,
        ?string $username,
        ?string $avatar,
        string $type,
        array $attributes,
        bool $keepsAvatar = false,
    ): array {
        return [
            'key' => "{$platform->value}:{$platformUserId}",
            'platform' => $platform->value,
            'platform_user_id' => $platformUserId,
            'name' => $name,
            'username' => $username,
            'avatar' => $avatar,
            'type' => $type,
            'keeps_avatar' => $keepsAvatar,
            'attributes' => $attributes,
        ];
    }

    /**
     * Only a named internal app page is kept, as its route name and parameters,
     * never the raw path: the URL is rebuilt from the route when the flow ends.
     *
     * @return array{name: string, parameters: array<string, string>}
     */
    private static function returnTarget(?string $path): array
    {
        $fallback = ['name' => self::DEFAULT_RETURN_ROUTE, 'parameters' => []];

        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpException|BadRequestException) {
            return $fallback;
        }

        $name = (string) $route->getName();

        if (! str_starts_with($name, 'app.') || str_starts_with($name, 'app.social.') || ! in_array('GET', $route->methods(), true)) {
            return $fallback;
        }

        return [
            'name' => $name,
            'parameters' => array_map(strval(...), array_filter($route->parameters(), is_scalar(...))),
        ];
    }

    private static function urlFor(mixed $target): string
    {
        return rescue(
            fn (): string => route((string) data_get($target, 'name'), (array) data_get($target, 'parameters', [])),
            fn (): string => self::defaultReturnUrl(),
            report: false,
        );
    }

    private function save(): void
    {
        Session::put(self::SESSION_KEY, $this->data);
    }
}
