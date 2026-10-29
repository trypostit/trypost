<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Concerns;

use App\Actions\Post\BuildPublishPageProps;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\PostStatusRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait RendersPublishPage
{
    private const array PUBLISH_PAGE_ROUTES = ['app.posts.index', 'app.channels.publish'];

    private function renderPublishPage(Request $request, Workspace $workspace, ?SocialAccount $channel = null): Response|RedirectResponse
    {
        $composerPost = null;

        $editPostId = $request->query('edit');

        if (is_string($editPostId) && Str::isUuid($editPostId)) {
            $composerPost = $workspace->posts()
                ->with(['socialAccount', 'labels'])
                ->findOrFail($editPostId);
            $this->authorize('update', $composerPost);
            $composerPost->unsetRelation('user')->unsetRelation('approvalRequestedBy');

            if (PostStatusRules::blocksEditing($composerPost) || ! $this->canOpenComposer($composerPost)) {
                return redirect()->route('app.posts.index', ['post' => $composerPost->id]);
            }
        }

        return Inertia::render('publish/Index', BuildPublishPageProps::handle($request, $workspace, $channel, $composerPost));
    }

    /**
     * Where a post saved or deleted from a composer or post dialog returns: the
     * Publish page it came from on the tab given, or any other page of the app
     * exactly as it was.
     *
     * @param  array<string, string>  $publishQuery
     */
    private function originPageUrl(array $publishQuery = []): string
    {
        $previousRoute = (string) $this->previousRouteName();

        return match (true) {
            in_array($previousRoute, self::PUBLISH_PAGE_ROUTES, true) => $this->publishPageUrl($publishQuery),
            default => $this->previousUrl(),
        };
    }

    /**
     * The Publish page (all channels or one channel) the request was sent from,
     * with its tab, time zone and filters, minus the composer and notes context.
     *
     * @param  array<string, string>  $extraQuery
     */
    private function publishPageReturnUrl(array $extraQuery = []): ?string
    {
        return in_array($this->previousRouteName(), self::PUBLISH_PAGE_ROUTES, true)
            ? $this->publishPageUrl($extraQuery)
            : null;
    }

    /**
     * @param  array<string, string>  $extraQuery
     */
    private function publishPageUrl(array $extraQuery): string
    {
        return $this->previousUrl(
            except: ['edit', 'notes', 'note', 'post', 'compose', 'date', 'ai', 'assistant'],
            extraQuery: $extraQuery,
        );
    }

    /**
     * The page the request was sent from on this app, whatever host the Referer
     * names. Its query string is kept as sent, minus the keys in `$except`, with
     * `$extraQuery` replacing or adding keys.
     *
     * @param  list<string>  $except
     * @param  array<string, string>  $extraQuery
     */
    private function previousUrl(array $except = [], array $extraQuery = []): string
    {
        $previous = $this->previousUrlParts();
        $path = data_get($previous, 'path');

        $pairs = collect(explode('&', (string) data_get($previous, 'query')))
            ->reject(fn (string $pair): bool => blank($pair) || in_array($this->queryKey($pair), $except, true))
            ->map(fn (string $pair): string => array_key_exists($this->queryKey($pair), $extraQuery)
                ? Arr::query([$this->queryKey($pair) => $extraQuery[$this->queryKey($pair)]])
                : $pair);

        $added = array_diff_key($extraQuery, array_flip($pairs->map(fn (string $pair): string => $this->queryKey($pair))->all()));
        $query = $pairs->when($added !== [], fn (Collection $pairs): Collection => $pairs->push(Arr::query($added)))->implode('&');
        $url = url(filled($path) ? $path : '/');

        return filled($query) ? "{$url}?{$query}" : $url;
    }

    private function previousRouteName(): ?string
    {
        $path = data_get($this->previousUrlParts(), 'path');

        try {
            return Route::getRoutes()->match(Request::create(filled($path) ? $path : '/'))->getName();
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    /**
     * The Referer split by parse_url(), its path relative to this app's root
     * (base path included) when it is a page of this app.
     *
     * @return array<string, int|string>
     */
    private function previousUrlParts(): array
    {
        $previous = url()->previous();
        $root = url('/');
        $onThisApp = $previous === $root || Str::startsWith($previous, ["{$root}/", "{$root}?"]);

        return parse_url($onThisApp ? Str::after($previous, $root) : $previous) ?: [];
    }

    private function queryKey(string $pair): string
    {
        return urldecode(Str::before($pair, '='));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function redirectToComposer(Request $request, string $route, array $query = []): RedirectResponse
    {
        return redirect()->route($route, array_filter($query, fn (mixed $value): bool => $value !== null && $value !== ''))
            ->with('flash', [
                ...$request->session()->get('flash', []),
                'openPostComposer' => [
                    'date' => $request->query('date'),
                    'assistant' => $request->boolean('ai') || $request->boolean('assistant'),
                ],
            ]);
    }

    private function canOpenComposer(Post $post): bool
    {
        if (! $post->hasChannel()) {
            return $post->status === PostStatus::Draft;
        }

        return true;
    }
}
