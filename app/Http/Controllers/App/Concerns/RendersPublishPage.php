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
    private function renderPublishPage(Request $request, Workspace $workspace, ?SocialAccount $channel = null): Response|RedirectResponse
    {
        $composerPost = null;

        $editPostId = $request->query('edit');

        if (is_string($editPostId) && Str::isUuid($editPostId)) {
            $composerPost = $workspace->posts()
                ->with(['postPlatforms' => fn ($query) => $query->enabled()->with('socialAccount'), 'labels'])
                ->findOrFail($editPostId);
            $this->authorize('update', $composerPost);

            if (PostStatusRules::blocksEditing($composerPost) || ! $this->canOpenComposer($composerPost)) {
                return redirect()->route('app.posts.index', ['post' => $composerPost->id]);
            }
        }

        return Inertia::render('publish/Index', BuildPublishPageProps::handle($request, $workspace, $channel, $composerPost));
    }

    /**
     * The Publish page (all channels or one channel) the request was sent from,
     * with its tab, time zone and filters, minus the composer and notes context.
     *
     * @param  array<string, string>  $extraQuery
     */
    private function publishPageReturnUrl(array $extraQuery = []): ?string
    {
        if (! in_array($this->previousRouteName(), ['app.posts.index', 'app.channels.publish'], true)) {
            return null;
        }

        $previous = parse_url(url()->previous());
        $path = data_get($previous, 'path', '/');

        parse_str(data_get($previous, 'query', ''), $query);

        $query = [
            ...Arr::except($query, ['edit', 'notes', 'note', 'post', 'compose', 'date', 'ai', 'assistant']),
            ...$extraQuery,
        ];

        $url = url($path);

        if ($query === []) {
            return $url;
        }

        $queryString = Arr::query($query);

        return "{$url}?{$queryString}";
    }

    /**
     * The calendar (all channels or one channel) the request was sent from, as it was.
     */
    private function calendarReturnUrl(): ?string
    {
        return in_array($this->previousRouteName(), ['app.calendar', 'app.channels.calendar'], true)
            ? url()->previous()
            : null;
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
        $targets = $post->postPlatforms()->enabled()->get();

        if ($targets->isEmpty()) {
            return $post->status === PostStatus::Draft;
        }

        return $targets->count() === 1 && $targets->first()->social_account_id !== null;
    }
}
