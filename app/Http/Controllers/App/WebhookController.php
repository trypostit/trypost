<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use App\Http\Requests\App\Webhook\StoreWebhookRequest;
use App\Http\Requests\App\Webhook\UpdateWebhookRequest;
use App\Jobs\DispatchWebhook;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Services\WebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class WebhookController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('viewAny', Webhook::class);

        $webhooks = Webhook::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('webhooks/Index', [
            'webhooks' => $webhooks,
            'eventTypes' => array_column(EventType::cases(), 'value'),
        ]);
    }

    public function show(Webhook $webhook): Response
    {
        $this->authorize('view', $webhook);

        return Inertia::render('webhooks/Show', [
            'webhook' => $webhook->makeVisible('signing_secret'),
            'logs' => Inertia::scroll(
                fn () => $webhook->logs()->orderByDesc('created_at')->paginate((int) config('app.pagination.default')),
            ),
            'eventTypes' => array_column(EventType::cases(), 'value'),
        ]);
    }

    public function store(StoreWebhookRequest $request, WebhookService $webhookService): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('create', Webhook::class);

        try {
            $webhookService->ping($request->string('endpoint')->toString());
        } catch (RuntimeException $e) {
            return back()->withErrors([
                'endpoint' => $e->getMessage(),
            ]);
        }

        $webhook = Webhook::query()->create([
            'workspace_id' => $workspace->id,
            'endpoint' => $request->string('endpoint')->toString(),
            'events' => $request->validated('events'),
            'status' => Status::Enabled,
            'signing_secret' => 'whsec_'.Str::random(32),
            'consecutive_failures' => 0,
        ]);

        session()->flash('flash.banner', __('webhooks.flash.created'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.webhooks.show', $webhook);
    }

    public function update(UpdateWebhookRequest $request, Webhook $webhook, WebhookService $webhookService): RedirectResponse
    {
        $this->authorize('update', $webhook);

        $validated = $request->validated();

        if (
            isset($validated['endpoint'])
            && $validated['endpoint'] !== $webhook->endpoint
        ) {
            try {
                $webhookService->ping($validated['endpoint']);
            } catch (RuntimeException $e) {
                return back()->withErrors([
                    'endpoint' => $e->getMessage(),
                ]);
            }
        }

        if (
            isset($validated['status'])
            && $validated['status'] === Status::Enabled->value
            && $webhook->status !== Status::Enabled
        ) {
            $validated['consecutive_failures'] = 0;
            $validated['paused_at'] = null;
        }

        $webhook->update($validated);

        session()->flash('flash.banner', __('webhooks.flash.updated'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function rotateSecret(Webhook $webhook): RedirectResponse
    {
        $this->authorize('update', $webhook);

        $webhook->update([
            'signing_secret' => 'whsec_'.Str::random(32),
        ]);

        session()->flash('flash.banner', __('webhooks.flash.secret_rotated'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function replay(Webhook $webhook, WebhookLog $webhookLog): RedirectResponse
    {
        $this->authorize('replay', [$webhookLog, $webhook]);

        DispatchWebhook::dispatch(
            $webhook,
            $webhookLog->event_type,
            data_get($webhookLog->payload, 'data', $webhookLog->payload) ?? [],
            force: true,
        );

        session()->flash('flash.banner', __('webhooks.flash.replayed'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $this->authorize('delete', $webhook);

        $webhook->delete();

        session()->flash('flash.banner', __('webhooks.flash.deleted'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.webhooks.index');
    }
}
