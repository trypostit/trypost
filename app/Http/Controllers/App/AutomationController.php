<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Automation\Automation\ActivateAutomation;
use App\Actions\Automation\Automation\CreateAutomation;
use App\Actions\Automation\Automation\DeleteAutomation;
use App\Actions\Automation\Automation\GetAutomationEditorData;
use App\Actions\Automation\Automation\GetAutomationInvocations;
use App\Actions\Automation\Automation\GetAutomationMetrics;
use App\Actions\Automation\Automation\ListAutomations;
use App\Actions\Automation\Automation\PauseAutomation;
use App\Actions\Automation\Automation\UpdateAutomation;
use App\Actions\Automation\Run\RetryRunFromNode;
use App\Actions\Automation\Run\TestAutomation;
use App\Enums\Automation\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Automations\ActivateAutomationRequest;
use App\Http\Requests\App\Automations\InspectFeedRequest;
use App\Http\Requests\App\Automations\PauseAutomationRequest;
use App\Http\Requests\App\Automations\RetryRunRequest;
use App\Http\Requests\App\Automations\StoreAutomationRequest;
use App\Http\Requests\App\Automations\TestAutomationRequest;
use App\Http\Requests\App\Automations\UpdateAutomationRequest;
use App\Http\Resources\App\Automation\FeedInspectionResource;
use App\Http\Resources\App\PlatformConfigResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Http\Resources\AutomationInvocationResource;
use App\Http\Resources\AutomationNodeRunResource;
use App\Http\Resources\AutomationResource;
use App\Http\Resources\AutomationRunResource;
use App\Models\Automation;
use App\Models\AutomationNodeRun;
use App\Models\AutomationRun;
use App\Services\Automation\ExpressionResolver;
use App\Services\Automation\FeedParser;
use App\Services\Brand\SafeHttpFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AutomationController extends Controller
{
    public function index(ListAutomations $list): Response
    {
        $this->authorize('viewAny', Automation::class);

        $workspace = request()->user()->currentWorkspace;

        $automations = Inertia::scroll(fn () => AutomationResource::collection(
            $list($workspace)
        ));

        return Inertia::render('automations/Index', [
            'automations' => $automations,
        ]);
    }

    public function store(StoreAutomationRequest $request, CreateAutomation $create): RedirectResponse
    {
        $this->authorize('create', Automation::class);

        $automation = $create(
            $request->user()->currentWorkspace,
            $request->user(),
        );

        return redirect()->route('app.automations.workflow', $automation->id);
    }

    public function show(Automation $automation): RedirectResponse
    {
        $this->authorize('view', $automation);

        // A draft opens on the builder to be set up; a live automation (active
        // or paused) opens on its metrics, where the user watches it run.
        $tab = $automation->status === Status::Draft ? 'workflow' : 'metrics';

        return redirect()->route("app.automations.{$tab}", $automation->id);
    }

    public function workflow(Automation $automation, GetAutomationEditorData $editorData): Response
    {
        $this->authorize('update', $automation);

        ['socialAccounts' => $socialAccounts, 'pinterestBoards' => $pinterestBoards, 'tiktokCreatorInfos' => $tiktokCreatorInfos] = $editorData($automation);

        $platformConfigs = $socialAccounts->mapWithKeys(fn ($account) => [
            $account->id => new PlatformConfigResource($account),
        ]);

        return Inertia::render('automations/Form', [
            'automation' => AutomationResource::make($automation),
            'socialAccounts' => SocialAccountResource::collection($socialAccounts),
            'platformConfigs' => $platformConfigs,
            'pinterestBoards' => $pinterestBoards,
            'tiktokCreatorInfos' => $tiktokCreatorInfos,
        ]);
    }

    public function invocations(Automation $automation, GetAutomationInvocations $invocations): Response
    {
        $this->authorize('view', $automation);

        $status = request()->string('status')->toString() ?: null;
        $search = request()->string('search')->toString() ?: null;

        return Inertia::render('automations/Invocations', [
            'automation' => AutomationResource::make($automation),
            'invocations' => Inertia::scroll(fn () => AutomationInvocationResource::collection(
                $invocations($automation, $status, $search)
            )),
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }

    public function settings(Automation $automation): Response
    {
        $this->authorize('view', $automation);

        return Inertia::render('automations/Settings', [
            'automation' => AutomationResource::make($automation),
        ]);
    }

    public function metrics(Automation $automation, GetAutomationMetrics $metrics): Response
    {
        $this->authorize('view', $automation);

        $end = (request()->date('end') ?? now())->startOfDay();
        $start = (request()->date('start') ?? now()->subDays(6))->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        // Cap the window so a hand-edited URL can't request a multi-year, daily
        // bucketed series.
        if ($start->diffInDays($end) > 366) {
            $start = $end->copy()->subDays(366);
        }

        return Inertia::render('automations/Metrics', [
            'automation' => AutomationResource::make($automation),
            'metrics' => $metrics($automation, $start, $end),
            'filters' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
        ]);
    }

    public function update(UpdateAutomationRequest $request, Automation $automation, UpdateAutomation $update): RedirectResponse
    {
        $this->authorize('update', $automation);

        $update($automation, $request->validated());

        return back();
    }

    public function destroy(Automation $automation, DeleteAutomation $delete): RedirectResponse
    {
        $this->authorize('delete', $automation);
        $delete($automation);

        session()->flash('flash.banner', __('automations.flash.deleted'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.automations.index');
    }

    public function activate(ActivateAutomationRequest $request, Automation $automation, ActivateAutomation $activate): RedirectResponse
    {
        $this->authorize('activate', $automation);

        $activate($automation);

        return back();
    }

    public function pause(PauseAutomationRequest $request, Automation $automation, PauseAutomation $pause): RedirectResponse
    {
        $this->authorize('pause', $automation);

        $pause($automation);

        return back();
    }

    public function retryRun(
        RetryRunRequest $request,
        RetryRunFromNode $retry,
        Automation $automation,
        AutomationRun $run,
    ): HttpResponse {
        $this->authorize('update', $automation);
        abort_unless($run->automation_id === $automation->id, 404);

        $nodeId = $request->validated('node_id') ?? $run->current_node_id;
        $retry($run, $nodeId);

        return response()->noContent();
    }

    public function test(TestAutomationRequest $request, Automation $automation, TestAutomation $test): JsonResponse
    {
        $this->authorize('update', $automation);

        $run = $test($automation, (bool) $request->validated('with_real_data', false));

        return response()->json(['run_id' => $run->id]);
    }

    public function inspectFeed(
        InspectFeedRequest $request,
        Automation $automation,
        ExpressionResolver $resolver,
        SafeHttpFetcher $safeHttp,
        FeedParser $parser,
    ): JsonResponse|FeedInspectionResource {
        $this->authorize('update', $automation);

        $feedUrl = $resolver->resolve(
            (string) $request->validated('feed_url'),
            ['variables' => $automation->resolvedVariables()],
        );

        // SafeHttpFetcher::get() bundles the SSRF guard, a request timeout, a
        // redirect cap and a branded user-agent — so a slow or hostile feed can't
        // hang this synchronous request. It throws on SSRF, timeout or non-2xx.
        try {
            $response = $safeHttp->get($feedUrl);
        } catch (RuntimeException) {
            return response()->json(['message' => __('automations.errors.fetch_rss_request_failed')], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items = $parser->parse($response->body());

        if ($items === null) {
            return response()->json(['message' => __('automations.errors.fetch_rss_malformed')], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new FeedInspectionResource($items[0] ?? []);
    }

    public function showRun(Automation $automation, AutomationRun $run): JsonResponse
    {
        $this->authorize('view', $automation);
        abort_unless($run->automation_id === $automation->id, 404);

        // Aggregate the node runs of every branch forked by a fan-out so the test
        // panel shows the whole execution, not just the branch the root walked.
        $rootId = $run->rootId();

        $nodeRuns = AutomationNodeRun::query()
            ->whereHas('run', fn ($query) => $query
                ->where('id', $rootId)
                ->orWhere('root_run_id', $rootId))
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'run' => AutomationRunResource::make($run)->resolve(),
            'node_runs' => AutomationNodeRunResource::collection($nodeRuns)->resolve(),
        ]);
    }
}
