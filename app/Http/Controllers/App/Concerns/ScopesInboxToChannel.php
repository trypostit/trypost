<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Concerns;

use App\Dto\Inbox\InboxFilters;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * A channel's own inbox (channels/{account}/inbox, like its publish and
 * insights pages) is the inbox with the channel held by the URL instead of
 * the channel filter, and every redirect stays under the channel.
 */
trait ScopesInboxToChannel
{
    use EnsuresChannelInCurrentWorkspace;

    private function scopedFilters(Request $request, InboxFilters $filters, ?SocialAccount $account): InboxFilters
    {
        if (! $account) {
            return $filters;
        }

        $this->ensureCurrentWorkspace($request, $account);
        abort_unless($account->platform->inboxEnabled(), Response::HTTP_NOT_FOUND);

        return $filters->forChannel($account->id);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function inboxRedirect(string $route, array $parameters, ?SocialAccount $account): RedirectResponse
    {
        if (! $account) {
            return redirect()->route($route, $parameters);
        }

        return redirect()->route(match ($route) {
            'app.inbox.index' => 'app.channels.inbox',
            'app.inbox.messages.index' => 'app.channels.inbox.messages',
        }, ['account' => $account->id, ...Arr::except($parameters, ['channels'])]);
    }
}
