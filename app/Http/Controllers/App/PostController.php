<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Actions\Post\BuildCalendarPageProps;
use App\Actions\Post\BuildComposerProps;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\DeletePost;
use App\Actions\Post\DuplicatePost;
use App\Actions\Post\RecoverEmptyDraft;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\CreatedVia;
use App\Http\Controllers\App\Concerns\RendersPublishPage;
use App\Http\Requests\App\Post\StorePostRequest;
use App\Http\Requests\App\Post\UpdatePostRequest;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Support\PostStatusRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    use RendersPublishPage;

    public function index(Request $request): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $workspace);

        if ($request->boolean('compose')) {
            $this->authorize('createPost', $workspace);

            return $this->redirectToComposer($request, 'app.posts.index', [
                'tab' => $request->query('tab'),
                'labels' => $request->query('labels'),
                'untagged' => $request->query('untagged'),
                'channels' => $request->query('channels'),
                'tz' => $request->query('tz'),
                'status' => $request->query('status'),
            ]);
        }

        return $this->renderPublishPage($request, $workspace);
    }

    public function calendar(Request $request, ?string $view = null): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $workspace);

        if ($request->boolean('compose')) {
            $this->authorize('createPost', $workspace);

            return $this->redirectToComposer($request, 'app.calendar', [
                'view' => $view ?? $request->query('view'),
                'week' => $request->query('week'),
                'month' => $request->query('month'),
                'labels' => $request->query('labels'),
                'untagged' => $request->query('untagged'),
                'channels' => $request->query('channels'),
                'tz' => $request->query('tz'),
                'status' => $request->query('status'),
            ]);
        }

        return Inertia::render('posts/Calendar', BuildCalendarPageProps::handle($request, $workspace, null, $view ?? $request->query('view')));
    }

    public function composerData(Request $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        return response()->json([
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            ...BuildComposerProps::handle($workspace, true),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        return $this->redirectToComposer($request, 'app.posts.index');
    }

    public function store(StorePostRequest $request): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        if (! $workspace->socialAccounts()->exists()) {
            session()->flash('flash.banner', __('posts.flash.connect_first'));
            session()->flash('flash.bannerStyle', 'danger');

            return $request->user()->can('manageAccounts', $workspace)
                ? redirect()->route('app.workspace.channels')
                : redirect()->route('app.calendar');
        }

        $composition = [
            ...$request->only(['status', 'content', 'media', 'scheduled_at', 'queue', 'queue_slot', 'label_ids', 'destinations']),
            'created_via' => CreatedVia::Web,
        ];
        if ($request->filled('recover_post_id')) {
            $legacy = $workspace->posts()->findOrFail($request->input('recover_post_id'));
            $this->authorize('update', $legacy);
            $posts = RecoverEmptyDraft::execute($workspace, $request->user(), $legacy, $composition);
        } else {
            $posts = CreatePosts::execute($workspace, $request->user(), $composition);
        }

        return redirect($this->publishPageReturnUrl() ?? route('app.posts.index'))
            ->with('created_post_ids', $posts->pluck('id')->all());
    }

    public function platformMetrics(Request $request, Post $post, PostPlatform $postPlatform): JsonResponse
    {
        $this->authorize('view', $post);

        if ($postPlatform->post_id !== $post->id) {
            abort(404);
        }

        return response()->json(app(ReadPublicationAnalytics::class)->forPlatform($postPlatform));
    }

    public function edit(Request $request, Post $post): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $post);

        if ($request->query('tab') === 'comments' || $request->filled('comment')) {
            return redirect()->route('app.posts.index', [
                'notes' => $post->id,
                ...($request->filled('comment') ? ['note' => $request->query('comment')] : []),
            ]);
        }

        if (PostStatusRules::blocksEditing($post) || ! $this->canOpenComposer($post)) {
            return redirect()->route('app.posts.index', ['post' => $post->id]);
        }

        return redirect()->route('app.posts.index', [
            'edit' => $post->id,
        ]);

    }

    public function update(UpdatePostRequest $request, Post $post): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('update', $post);

        $result = UpdatePost::execute($workspace, $post, $request->validated(), $request->user());

        $action = data_get($result, 'action');

        if ($action === PostAction::Finalized) {
            session()->flash('flash.banner', __('posts.flash.cannot_edit_finalized'));
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        }

        if ($action === PostAction::Publishing) {
            return redirect()->route('app.posts.index', ['post' => $post->id]);
        }

        if ($action === PostAction::Scheduled) {
            session()->flash('flash.banner', __('posts.flash.scheduled'));
            session()->flash('flash.bannerStyle', 'success');

            return redirect($this->publishPageReturnUrl() ?? route('app.posts.index', ['post' => $post->id]));
        }

        $publishPage = $this->publishPageReturnUrl();

        return $publishPage ? redirect($publishPage) : back();
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('delete', $post);

        if (PostStatusRules::blocksDeletion($post)) {
            session()->flash('flash.banner', __('posts.flash.cannot_delete_published'));
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        }

        DeletePost::execute($post);

        session()->flash('flash.banner', __('posts.flash.deleted'));
        session()->flash('flash.bannerStyle', 'success');

        $allowedRedirects = ['app.posts.index', 'app.calendar'];

        if ($redirect = $request->input('redirect')) {
            if (in_array($redirect, $allowedRedirects)) {
                return redirect()->route($redirect);
            }
        }

        return redirect($this->publishPageReturnUrl() ?? $this->calendarReturnUrl() ?? route('app.posts.index'));
    }

    public function duplicate(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('duplicate', $post);

        $post->load(['postPlatforms', 'labels']);

        $copy = DuplicatePost::execute($post, $request->user(), $request->input('post_platform_id'));

        session()->flash('flash.banner', __('posts.flash.duplicated'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect($this->publishPageReturnUrl(['edit' => $copy->id]) ?? route('app.posts.edit', $copy));
    }
}
