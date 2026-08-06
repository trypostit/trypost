<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class BillingController extends Controller
{
    public function subscribe(): RedirectResponse
    {
        return redirect()->route('app.welcome.persona');
    }

    public function processing(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;
        $sessionId = $request->query('session_id');

        // Consume the checkout session once: `fromCheckout` is true only the first
        // time this session_id is seen, so a back-button/refresh to the success URL
        // can't re-fire `checkout.completed`. `Cache::add` is atomic — it returns
        // true only when the key didn't exist yet.
        $fromCheckout = is_string($sessionId) && $sessionId !== ''
            && Cache::add("checkout_tracked:{$sessionId}", true, now()->addDay());

        return Inertia::render('billing/Processing', [
            'subscriptionActive' => $account && $account->subscribed(Account::SUBSCRIPTION_NAME),
            'fromCheckout' => $fromCheckout,
            'persona' => $request->user()->persona?->value,
            'conversion' => $fromCheckout && $account?->stripe_id
                ? fn () => $this->buildConversionData($account, $sessionId)
                : null,
        ]);
    }

    /**
     * @return array{value: float, currency: string, transaction_id: string}|null
     */
    private function buildConversionData(Account $account, string $sessionId): ?array
    {
        try {
            $session = $account->stripe()->checkout->sessions->retrieve(
                $sessionId,
                ['expand' => ['line_items.data.price']],
            );
        } catch (Throwable) {
            return null;
        }

        if (data_get($session, 'customer') !== $account->stripe_id) {
            return null;
        }

        $unitAmount = data_get($session, 'line_items.data.0.price.unit_amount');
        $currency = data_get($session, 'line_items.data.0.price.currency');
        $transactionId = data_get($session, 'id');

        if (! is_int($unitAmount) || ! is_string($currency) || ! is_string($transactionId)) {
            return null;
        }

        return [
            'value' => $unitAmount / 100,
            'currency' => strtoupper($currency),
            'transaction_id' => $transactionId,
        ];
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);

        return Inertia::render('settings/account/Billing', [
            'hasSubscription' => $account->subscribed(Account::SUBSCRIPTION_NAME),
            'onTrial' => $account->isOnTrial(),
            'trialEndsAt' => $account->activeTrialEndsAt(),
            'subscription' => $subscription?->only([
                'stripe_status',
                'ends_at',
            ]),
            'plan' => $account->plan,
            'workspaceCount' => $account->workspaces()->count(),
            'invoices' => $account->invoices()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'date' => $invoice->date(),
                'total' => $invoice->total(),
                'status' => $invoice->status,
                'invoice_pdf' => $invoice->invoice_pdf,
            ]),
            'defaultPaymentMethod' => $account->displayablePaymentMethod(),
        ]);
    }

    public function swapToYearly(Request $request): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);
        abort_unless($account->subscribed(Account::SUBSCRIPTION_NAME), SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No active subscription');

        $plan = $account->plan;
        $yearlyPriceId = $plan?->stripe_yearly_price_id;

        abort_if($yearlyPriceId === null, SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No annual price configured');

        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);

        abort_if($subscription === null, SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No active subscription');

        if ($subscription->stripe_price === $yearlyPriceId) {
            return redirect()->route('app.billing.index');
        }

        $authorization = Gate::inspect('swapPlan', [$account]);

        if ($authorization->denied()) {
            return back()->with('flash.error', $authorization->message());
        }

        $subscription->swap($yearlyPriceId);

        return redirect()->route('app.billing.index')
            ->with('flash.success', __('billing.flash.switched_to_yearly'));
    }

    public function portal(Request $request): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        return $account->redirectToBillingPortal(
            route('app.billing.index')
        );
    }
}
