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
     * Publish page it came from on the tab given, any other page of the app
     * exactly as it was, or the Publish list when it came from outside the app.
     *
     * @param  array<string, string>  $publishQuery
     */
    private function originPageUrl(array $publishQuery = []): string
    {
        $previousRoute = (string) $this->previousRouteName();

        return match (true) {
            in_array($previousRoute, self::PUBLISH_PAGE_ROUTES, true) => $this->publishPageUrl($publishQuery),
            ! Str::startsWith($previousRoute, 'app.') => route('app.posts.index', $publishQuery),
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
     * The page the request was sent from on this app's host, whatever host the
     * Referer names.
     *
     * @param  list<string>  $except
     * @param  array<string, string>  $extraQuery
     */
    private function previousUrl(array $except = [], array $extraQuery = []): string
    {
        $previous = parse_url(url()->previous());

        parse_str(data_get($previous, 'query', ''), $query);

        $query = [...Arr::except($query, $except), ...$extraQuery];
        $url = url(data_get($previous, 'path', '/'));

        if ($query === []) {
            return $url;
        }

        $queryString = Arr::query($query);

        return "{$url}?{$queryString}";
    }

    private function previousRouteName(): ?string
    {
        try {
            return Route::getRoutes()->match(Request::create(data_get(parse_url(url()->previous()), 'path', '/')))->getName();
        } catch (HttpExceptionInterface) {
            return null;
        }
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
