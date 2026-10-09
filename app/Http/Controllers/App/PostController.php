<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\BuildCalendarPageProps;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\DeletePost;
use App\Actions\Post\DuplicatePost;
use App\Actions\Post\RecoverEmptyDraft;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Http\Controllers\App\Concerns\RendersPublishPage;
use App\Http\Requests\App\Post\StorePostRequest;
use App\Http\Requests\App\Post\UpdatePostRequest;
use App\Models\Post;
use App\Support\PostStatusRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
                'day' => $request->query('day'),
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

    public function create(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        return $this->redirectToComposer($request, 'app.posts.index');
    }

    public function store(StorePostRequest $request): RedirectResponse|HttpResponse
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

        $tab = match ($request->input('status')) {
            PostStatus::Draft->value => ['tab' => 'drafts'],
            PostStatus::Scheduled->value, PostStatus::Publishing->value => ['tab' => 'queue'],
            default => [],
        };

        return redirect($this->publishPageReturnUrl($tab) ?? route('app.posts.index', $tab))
            ->with('created_post_ids', $posts->pluck('id')->all());
    }

    public function edit(Request $request, Post $post): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $post);

        if ($request->filled('comment')) {
            return redirect()->route('app.posts.index', [
                'notes' => $post->id,
                'note' => $request->query('comment'),
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
            return redirect($this->publishPageReturnUrl(['tab' => 'queue']) ?? route('app.posts.index', ['tab' => 'queue']));
        }

        if ($action === PostAction::Scheduled) {
            return redirect($this->publishPageReturnUrl(['tab' => 'queue']) ?? route('app.posts.index', ['post' => $post->id]));
        }

        $publishPage = $this->publishPageReturnUrl(
            $request->validated('status') === PostStatus::Draft->value ? ['tab' => 'drafts'] : [],
        );

        return $publishPage ? redirect($publishPage) : back();
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('delete', $post);

        try {
            DeletePost::execute($post, respectStatus: true);
        } catch (ValidationException $exception) {
            session()->flash('flash.banner', data_get($exception->errors(), 'post.0'));
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        }

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

        $post->load(['socialAccount', 'labels']);

        $copy = DuplicatePost::execute($post, $request->user());

        return redirect($this->publishPageReturnUrl(['edit' => $copy->id]) ?? route('app.posts.edit', $copy));
    }
}
