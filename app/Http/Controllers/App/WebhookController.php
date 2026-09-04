<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Webhook\Status;
use App\Http\Requests\App\Webhook\StoreWebhookRequest;
use App\Http\Requests\App\Webhook\UpdateWebhookRequest;
use App\Jobs\DispatchWebhook;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Services\WebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
    }

    public function store(StoreWebhookRequest $request, WebhookService $webhookService): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('create', Webhook::class);

        $validated = $request->validated();
        $endpoint = data_get($validated, 'endpoint');

        if ($error = $this->endpointError($webhookService, $endpoint)) {
            return $error;
        }

        $webhook = Webhook::query()->create([
            'workspace_id' => $workspace->id,
            'endpoint' => $endpoint,
            'events' => data_get($validated, 'events'),
            'status' => Status::Enabled,
            'signing_secret' => Webhook::generateSigningSecret(),
        ]);

        session()->flash('flash.banner', __('webhooks.flash.created'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.webhooks.show', $webhook);
    }

    public function update(UpdateWebhookRequest $request, Webhook $webhook, WebhookService $webhookService): RedirectResponse
    {
        $this->authorize('update', $webhook);

        $validated = $request->validated();
        $endpoint = data_get($validated, 'endpoint');

        if ($endpoint !== $webhook->endpoint && ($error = $this->endpointError($webhookService, $endpoint))) {
            return $error;
        }

        if (data_get($validated, 'status') === Status::Enabled->value) {
            $validated['consecutive_failures'] = 0;
            $validated['paused_at'] = null;
        }

        $webhook->update($validated);

        session()->flash('flash.banner', __('webhooks.flash.updated'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function sendTest(Webhook $webhook, WebhookService $webhookService): RedirectResponse
    {
        $this->authorize('update', $webhook);

        try {
            $webhookService->ping($webhook->endpoint, $webhook->signing_secret);
        } catch (RuntimeException $e) {
            session()->flash('flash.banner', $e->getMessage());
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        }

        session()->flash('flash.banner', __('webhooks.flash.tested'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function rotateSecret(Webhook $webhook): RedirectResponse
    {
        $this->authorize('update', $webhook);

        $webhook->update([
            'signing_secret' => Webhook::generateSigningSecret(),
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
            data_get($webhookLog->payload, 'data') ?? [],
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

    private function endpointError(WebhookService $webhookService, mixed $endpoint): ?RedirectResponse
    {
        if (! is_string($endpoint)) {
            return null;
        }

        try {
            $webhookService->assertEndpointAllowed($endpoint);
        } catch (RuntimeException $e) {
            return back()->withErrors([
                'endpoint' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
