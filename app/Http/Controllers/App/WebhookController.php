<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Webhook\CreateWebhook;
use App\Actions\Webhook\DeleteWebhook;
use App\Actions\Webhook\ReplayWebhookLog;
use App\Actions\Webhook\RotateWebhookSecret;
use App\Actions\Webhook\SendWebhookTest;
use App\Actions\Webhook\UpdateWebhook;
use App\Http\Requests\App\Webhook\StoreWebhookRequest;
use App\Http\Requests\App\Webhook\UpdateWebhookRequest;
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

        try {
            $webhook = CreateWebhook::execute($workspace, $request->validated(), $webhookService);
        } catch (RuntimeException $e) {
            return back()->withErrors([
                'endpoint' => $e->getMessage(),
            ]);
        }

        session()->flash('flash.banner', __('webhooks.flash.created'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.webhooks.show', $webhook);
    }

    public function update(UpdateWebhookRequest $request, Webhook $webhook, WebhookService $webhookService): RedirectResponse
    {
        $this->authorize('update', $webhook);

        try {
            UpdateWebhook::execute($webhook, $request->validated(), $webhookService);
        } catch (RuntimeException $e) {
            return back()->withErrors([
                'endpoint' => $e->getMessage(),
            ]);
        }

        session()->flash('flash.banner', __('webhooks.flash.updated'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function sendTest(Webhook $webhook, WebhookService $webhookService): RedirectResponse
    {
        $this->authorize('update', $webhook);

        try {
            SendWebhookTest::execute($webhook, $webhookService);
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

        RotateWebhookSecret::execute($webhook);

        session()->flash('flash.banner', __('webhooks.flash.secret_rotated'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function replay(Webhook $webhook, WebhookLog $webhookLog): RedirectResponse
    {
        if ($webhookLog->webhook_id !== $webhook->id) {
            abort(404);
        }

        $this->authorize('replay', [$webhookLog, $webhook]);

        ReplayWebhookLog::execute($webhook, $webhookLog);

        session()->flash('flash.banner', __('webhooks.flash.replayed'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $this->authorize('delete', $webhook);

        DeleteWebhook::execute($webhook);

        session()->flash('flash.banner', __('webhooks.flash.deleted'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.webhooks.index');
    }
}
