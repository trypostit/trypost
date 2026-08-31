<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Social\Meta\GrantedPermissions;
use App\Services\Social\Meta\ManagedPageList;
use Illuminate\Support\Facades\Http;
use Inertia\Response as InertiaResponse;

/**
 * What the Facebook and Instagram-via-Facebook connect flows share: one Meta app, one
 * page walk, and one set of answers when the walk has nothing to offer.
 */
abstract class MetaController extends SocialController
{
    protected string $driver = 'facebook';

    private ?float $deadline = null;

    /** Graph fields the page walk asks for. */
    protected string $pageFields;

    /** Popup key for "this login has no pages of the kind we want". */
    protected string $noPagesKey;

    /** When the whole callback must stop reading pages, shared by every phase of it. */
    protected function deadline(): float
    {
        return $this->deadline ??= microtime(true) + (int) config('trypost.meta_page_walk_seconds');
    }

    /** Meta's app review wants to see this called; the answer is unused, so nothing it does can fail the connect. */
    protected function touchProfile(string $userToken): void
    {
        rescue(fn () => Http::timeout(5)->connectTimeout(5)->get("{$this->graphApi()}/me", [
            'fields' => 'id,name',
            'access_token' => $userToken,
        ]), report: false);
    }

    /**
     * The scopes this login did not refuse, or the popup refusing the connect because
     * one the platform needs to publish is among them.
     *
     * @return array<int, string>|InertiaResponse
     */
    protected function grantedScopes(string $userToken): array|InertiaResponse
    {
        $granted = GrantedPermissions::for($this->graphApi(), $userToken, $this->scopes);

        return array_diff($this->platform->requiredPublishScopes(), $granted) === []
            ? $granted
            : $this->popupCallback(false, __('accounts.popup_callback.publish_permission_refused'), $this->platform->value);
    }

    /**
     * A walk that could not finish outranks the other answers, since neither would be
     * true of what it did not read.
     *
     * @param  array<int, array<string, mixed>>  $listed
     */
    protected function noPagesOnOffer(ManagedPageList $walk, array $listed): InertiaResponse
    {
        return $this->popupCallback(false, __(match (true) {
            ! $walk->complete => 'accounts.popup_callback.pages_read_incomplete',
            empty($listed) => $this->noPagesKey,
            default => 'accounts.popup_callback.pages_missing_permission',
        }), $this->platform->value);
    }
}
