<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\CreatePosts;
use App\Actions\Post\DeletePost;
use App\Actions\Post\DuplicatePost;
use App\Actions\Post\RecoverEmptyDraft;
use App\Actions\Post\UpdatePost;
use App\Actions\SocialAccount\ListPinterestBoards;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\App\Post\StorePostRequest;
use App\Http\Requests\App\Post\UpdatePostRequest;
use App\Http\Resources\Api\PostResource;
use App\Http\Resources\App\PlatformConfigResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Workspace;
use App\Services\Post\PostMetricsFetcher;
use App\Services\Social\TikTokCreatorInfo;
use App\Support\LinkTlds;
use App\Support\PostStatusRules;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function index(Request $request, ?string $status = null): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $workspace);

        $query = $workspace->posts()
            ->with(['postPlatforms' => fn ($query) => $query->enabled()->with('socialAccount'), 'user', 'labels']);

        if ($status) {
            $query = match ($status) {
                PostStatus::Draft->value => $query->draft(),
                PostStatus::Scheduled->value => $query->scheduled(),
                PostStatus::Published->value => $query->published(),
                default => $query,
            };
        }

        if ($search = $request->input('search')) {
            $query->whereLike('content', "%{$search}%");
        }

        $labelIds = $request->collect('labels')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        $query->when($labelIds, fn ($q) => $q->whereHas(
            'labels',
            fn ($q) => $q->whereIn('workspace_labels.id', $labelIds),
        ));

        $composerPost = null;
        if ($request->filled('edit')) {
            $composerPost = $workspace->posts()
                ->with(['postPlatforms' => fn ($query) => $query->enabled()->with('socialAccount'), 'labels'])
                ->findOrFail($request->query('edit'));
            $this->authorize('update', $composerPost);
            if (PostStatusRules::blocksEditing($composerPost) || ! $this->canOpenComposer($composerPost)) {
                return redirect()->route('app.posts.show', $composerPost);
            }
        }

        $composerRequested = $request->boolean('compose') || $composerPost !== null;

        return Inertia::render('posts/Index', [
            'workspace' => $workspace,
            'posts' => Inertia::scroll(fn () => $query->latest('scheduled_at')->paginate(config('app.pagination.default'))),
            'currentStatus' => $status,
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'filters' => [
                'search' => $request->input('search', ''),
                'labels' => $labelIds,
            ],
            'openComposer' => $composerRequested,
            'openComposerAssistant' => $request->boolean('assistant'),
            'initialComposerDate' => $request->query('date'),
            'openComposerComments' => $request->query('tab') === 'comments',
            'highlightCommentId' => $request->query('comment'),
            'authUserId' => $request->user()->id,
            'editPost' => $composerPost,
            ...$this->composerProps($workspace, $composerRequested),
        ]);
    }

    public function calendar(Request $request): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $workspace);

        $tz = 'UTC';
        $view = $request->input('view', 'week');

        $currentDay = $request->input('day')
            ? Carbon::parse($request->input('day'), $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();

        $weekStart = $request->input('week')
            ? Carbon::parse($request->input('week'), $tz)->startOfWeek()
            : Carbon::now($tz)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        $monthDate = $request->input('month')
            ? Carbon::parse($request->input('month'), $tz)->startOfMonth()
            : Carbon::now($tz)->startOfMonth();
        $monthStart = $monthDate->copy()->startOfMonth()->startOfWeek();
        $monthEnd = $monthDate->copy()->endOfMonth()->endOfWeek();

        $rangeStart = match ($view) {
            'day' => $currentDay,
            'month' => $monthStart,
            default => $weekStart,
        };
        $rangeEnd = match ($view) {
            'day' => $currentDay->copy()->endOfDay(),
            'month' => $monthEnd,
            default => $weekEnd,
        };

        $posts = $workspace->posts()
            ->with(['postPlatforms' => fn ($query) => $query->enabled()->with('socialAccount')])
            ->whereBetween('scheduled_at', [$rangeStart->copy()->utc(), $rangeEnd->copy()->utc()])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn ($post) => $post->scheduled_at?->setTimezone($tz)->format('Y-m-d'));

        return Inertia::render('posts/Calendar', [
            'workspace' => $workspace,
            'posts' => $posts,
            'currentDay' => $currentDay->format('Y-m-d'),
            'currentWeekStart' => $weekStart->format('Y-m-d'),
            'currentMonth' => $monthDate->format('Y-m-d'),
            'view' => $view,
            'openComposer' => $request->boolean('compose'),
            'initialComposerDate' => $request->query('date'),
            'authUserId' => $request->user()->id,
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            ...$this->composerProps($workspace, $request->boolean('compose')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerProps(Workspace $workspace, bool $requested): array
    {
        $socialAccounts = $requested ? $workspace->socialAccounts()->active()->get() : collect();

        return [
            'socialAccounts' => $requested ? SocialAccountResource::collection($socialAccounts) : [],
            'platformConfigs' => $socialAccounts->mapWithKeys(fn ($account) => [$account->id => new PlatformConfigResource($account)]),
            'pinterestBoards' => $socialAccounts->where('platform', Platform::Pinterest)->mapWithKeys(fn ($account) => [
                $account->id => rescue(fn () => ListPinterestBoards::execute($account), ['boards' => [], 'truncated' => false], report: false),
            ]),
            'tiktokCreatorInfos' => $socialAccounts->where('platform', Platform::TikTok)->mapWithKeys(fn ($account) => [
                $account->id => rescue(fn () => app(TikTokCreatorInfo::class)->fetch($account), null, report: false),
            ])->filter(),
            'xLinkTlds' => $requested && config('trypost.platforms.x.defuse_links') ? LinkTlds::all() : [],
            'signatures' => $requested ? $workspace->signatures()->get(['id', 'name', 'content']) : [],
        ];
    }

    public function create(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        return redirect()->route('app.posts.index', [
            'compose' => 1,
            'date' => $request->query('date'),
            ...($request->boolean('ai') ? ['assistant' => 1] : []),
        ]);
    }

    public function store(StorePostRequest $request): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        $socialAccounts = $workspace->socialAccounts()->active()->get();

        if ($socialAccounts->isEmpty()) {
            session()->flash('flash.banner', __('posts.flash.connect_first'));
            session()->flash('flash.bannerStyle', 'danger');

            return $request->user()->can('manageAccounts', $workspace)
                ? redirect()->route('app.accounts')
                : redirect()->route('app.calendar');
        }

        $composition = [
            ...$request->only(['status', 'content', 'media', 'scheduled_at', 'label_ids', 'destinations']),
            'created_via' => CreatedVia::Web,
        ];
        if ($request->filled('recover_post_id')) {
            $legacy = $workspace->posts()->findOrFail($request->input('recover_post_id'));
            $this->authorize('update', $legacy);
            $posts = RecoverEmptyDraft::execute($workspace, $request->user(), $legacy, $composition);
        } else {
            $posts = CreatePosts::execute($workspace, $request->user(), $composition);
        }

        return redirect()->route('app.posts.index')->with('created_post_ids', $posts->pluck('id')->all());
    }

    public function platformMetrics(Request $request, Post $post, PostPlatform $postPlatform): JsonResponse
    {
        $this->authorize('view', $post);

        if ($postPlatform->post_id !== $post->id) {
            abort(404);
        }

        return response()->json(app(PostMetricsFetcher::class)->forPlatform($postPlatform));
    }

    public function show(Request $request, Post $post): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $post);

        if (in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled], true) && $this->canOpenComposer($post)) {
            return redirect()->route('app.posts.edit', $post);
        }

        $post->load(['postPlatforms.socialAccount', 'labels']);

        return Inertia::render('posts/Show', [
            'workspace' => $workspace,
            'post' => (new PostResource($post))->resolve(),
        ]);
    }

    public function edit(Request $request, Post $post): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $post);

        if (PostStatusRules::blocksEditing($post) || ! $this->canOpenComposer($post)) {
            return redirect()->route('app.posts.show', $post);
        }

        return redirect()->route('app.posts.index', [
            'edit' => $post->id,
            ...($request->query('tab') === 'comments' ? ['tab' => 'comments'] : []),
            ...($request->filled('comment') ? ['comment' => $request->query('comment')] : []),
        ]);

    }

    public function update(UpdatePostRequest $request, Post $post): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('update', $post);

        $result = UpdatePost::execute($workspace, $post, $request->validated());

        $action = data_get($result, 'action');

        if ($action === PostAction::Finalized) {
            session()->flash('flash.banner', __('posts.flash.cannot_edit_finalized'));
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        }

        if ($action === PostAction::Publishing) {
            return redirect()->route('app.posts.show', $post);
        }

        if ($action === PostAction::Scheduled) {
            session()->flash('flash.banner', __('posts.flash.scheduled'));
            session()->flash('flash.bannerStyle', 'success');

            return redirect()->route('app.posts.show', $post);
        }

        return back();
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

        return redirect()->route('app.posts.index');
    }

    public function duplicate(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('duplicate', $post);

        $post->load(['postPlatforms', 'labels']);

        $copy = DuplicatePost::execute($post, $request->user(), $request->input('post_platform_id'));

        session()->flash('flash.banner', __('posts.flash.duplicated'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.posts.edit', $copy);
    }

    private function canOpenComposer(Post $post): bool
    {
        $targets = $post->postPlatforms()->enabled()->with('socialAccount')->get();

        if ($targets->isEmpty()) {
            return $post->status === PostStatus::Draft;
        }

        return $targets->count() === 1 && $targets->first()->socialAccount?->is_active === true;
    }
}
