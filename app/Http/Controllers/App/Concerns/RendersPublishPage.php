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
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
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
     * as it was.
     *
     * @param  array<string, string>  $publishQuery
     */
    private function originPageUrl(array $publishQuery = []): string
    {
        return $this->publishPageReturnUrl($publishQuery) ?? $this->previousUrl();
    }

    /**
     * The Publish page (all channels or one channel) the request was sent from,
     * with its tab, time zone and filters, minus the composer and notes context.
     * Null when the request came from any other page.
     *
     * @param  array<string, string>  $extraQuery
     */
    private function publishPageReturnUrl(array $extraQuery = []): ?string
    {
        if (! in_array($this->previousRoute()?->getName(), self::PUBLISH_PAGE_ROUTES, true)) {
            return null;
        }

        return $this->previousUrl(
            except: ['edit', 'notes', 'note', 'post', 'compose', 'date', 'ai', 'assistant'],
            extraQuery: $extraQuery,
        );
    }

    /**
     * The page the request was sent from, rebuilt with route() from the route its
     * Referer matches, so it always points at this app. Its query is kept, minus
     * the keys in `$except`, with `$extraQuery` replacing or adding keys. A Referer
     * that matches no route of the app returns to the home page.
     *
     * @param  list<string>  $except
     * @param  array<string, string>  $extraQuery
     */
    private function previousUrl(array $except = [], array $extraQuery = []): string
    {
        $route = $this->previousRoute();

        if (blank($route?->getName())) {
            return route('app.home');
        }

        $query = Uri::of(url()->previous())->query()->except($except);

        return route($route->getName(), [...$route->parameters(), ...$query, ...$extraQuery]);
    }

    /**
     * The route of the page the request was sent from. The Referer is read as a
     * request served by this one's script, so an app served from a subfolder
     * matches its routes without the subfolder.
     */
    private function previousRoute(): ?RoutingRoute
    {
        try {
            return Route::getRoutes()->match(Request::create(url()->previous(), server: request()->server->all()));
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
