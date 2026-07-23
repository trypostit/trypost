<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\CreatePost;
use App\Actions\Post\DeletePost;
use App\Actions\Post\DuplicatePost;
use App\Actions\Post\SyncPostPlatforms;
use App\Actions\Post\UpdatePost;
use App\Ai\Templates\AiContentTemplate;
use App\Ai\Templates\AiTemplateRegistry;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\App\Post\StorePostRequest;
use App\Http\Requests\App\Post\UpdatePostRequest;
use App\Http\Resources\Api\PostResource;
use App\Http\Resources\App\PlatformConfigResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Services\Post\PostMetricsFetcher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\TikTokCreatorInfo;
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
            ->with(['postPlatforms' => fn ($query) => $query->where('enabled', true)->with('socialAccount'), 'user', 'labels']);

        if ($status) {
            $query = match ($status) {
                PostStatus::Draft->value => $query->draft(),
                PostStatus::Scheduled->value => $query->scheduled(),
                PostStatus::Published->value => $query->published(),
                default => $query,
            };
        }

        if ($search = $request->input('search')) {
            $query->where('content', 'ilike', "%{$search}%");
        }

        $labelIds = $request->collect('labels')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        $query->when($labelIds, fn ($q) => $q->whereHas(
            'labels',
            fn ($q) => $q->whereIn('workspace_labels.id', $labelIds),
        ));

        return Inertia::render('posts/Index', [
            'workspace' => $workspace,
            'posts' => Inertia::scroll(fn () => $query->latest('scheduled_at')->paginate(config('app.pagination.default'))),
            'currentStatus' => $status,
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'filters' => [
                'search' => $request->input('search', ''),
                'labels' => $labelIds,
            ],
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
            ->with(['postPlatforms' => fn ($query) => $query->where('enabled', true)->with('socialAccount')])
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
        ]);
    }

    public function create(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $registry = app(AiTemplateRegistry::class);

        $templates = array_map(fn (AiContentTemplate $t) => [
            'key' => $t->key(),
            'name' => trans($t->name()),
            'description' => trans($t->description()),
            'preview' => $t->previewAsset(),
            'needs_account' => $t->needsAccount(),
            'supported_formats' => $t->supportedFormats(),
            'applies_brand_visuals' => $t->appliesBrandVisuals(),
        ], $registry->all());

        return Inertia::render('posts/Create', [
            'date' => $request->query('date'),
            'socialAccounts' => SocialAccountResource::collection(
                $workspace->socialAccounts()->active()->get()
            ),
            'templates' => $templates,
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

        $post = CreatePost::execute($workspace, $request->user(), [
            'date' => $request->input('date'),
            'media' => $request->input('media', []),
        ]);

        return Inertia::location(route('app.posts.edit', $post));
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

        if (in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled], true)) {
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

        if (PostStatusRules::blocksEditing($post)) {
            return redirect()->route('app.posts.show', $post);
        }

        if ($request->user()->can('update', $post)) {
            SyncPostPlatforms::execute($post);
        }

        $post->load(['postPlatforms.socialAccount', 'labels']);
        $socialAccounts = $workspace->socialAccounts()->active()->get();
        $labels = $workspace->labels;
        $signatures = $workspace->signatures;

        $platformConfigs = $socialAccounts->mapWithKeys(fn ($account) => [
            $account->id => new PlatformConfigResource($account),
        ]);

        $pinterestBoards = $socialAccounts
            ->where('platform', Platform::Pinterest)
            ->mapWithKeys(fn ($account) => [
                $account->id => rescue(
                    fn () => app(PinterestPublisher::class)->getBoards($account),
                    [],
                    report: false,
                ),
            ]);

        $tiktokCreatorInfos = $socialAccounts
            ->where('platform', Platform::TikTok)
            ->mapWithKeys(fn ($account) => [
                $account->id => rescue(
                    fn () => app(TikTokCreatorInfo::class)->fetch($account),
                    null,
                    report: false,
                ),
            ])
            ->filter();

        return Inertia::render('posts/Edit', [
            'workspace' => $workspace,
            'post' => $post,
            'socialAccounts' => $socialAccounts,
            'platformConfigs' => $platformConfigs,
            'pinterestBoards' => $pinterestBoards,
            'tiktokCreatorInfos' => $tiktokCreatorInfos,
            'labels' => $labels,
            'signatures' => $signatures,
            'authUserId' => $request->user()->id,
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

        $copy = DuplicatePost::execute($post, $request->user());

        session()->flash('flash.banner', __('posts.flash.duplicated'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.posts.edit', $copy);
    }
}
