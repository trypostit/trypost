<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Subscriptions\BuildSubscriptionChurn;
use App\Actions\Admin\Subscriptions\BuildSubscriptionOverview;
use App\Actions\Admin\Subscriptions\ListSubscriptions;
use App\Enums\Admin\SubscriptionStatusFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subscription\IndexSubscriptionRequest;
use App\Http\Resources\Admin\AdminSubscriptionResource;
use App\Models\Subscription;
use App\Support\Admin\StripeNetMrr;
use App\Support\Admin\SubscriptionPlans;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(IndexSubscriptionRequest $request): Response
    {
        $range = $request->dateRange();

        return Inertia::render('admin/billing/subscriptions/Index', [
            'overview' => fn (): array => BuildSubscriptionOverview::execute($range),
            'netMrr' => Inertia::defer(fn (): ?array => StripeNetMrr::read()),
            'churn' => fn (): array => BuildSubscriptionChurn::execute($range),
            'subscriptions' => Inertia::scroll(fn () => $this->subscriptions($request)),
            'statusCounts' => fn (): array => SubscriptionStatusFilter::counts(
                ListSubscriptions::execute(SubscriptionStatusFilter::All, $request->search())
                    ->withOnly([])
                    ->get(['stripe_status', 'ends_at']),
                now('UTC'),
            ),
            'ranges' => IndexSubscriptionRequest::RANGES,
            ...$request->rangeProps(),
            'filters' => [
                ...$request->rangeFilters(),
                'status' => $request->status()->value,
                'search' => $request->search() ?? '',
            ],
        ]);
    }

    private function subscriptions(IndexSubscriptionRequest $request): AnonymousResourceCollection
    {
        $prices = SubscriptionPlans::byPrice();

        return AdminSubscriptionResource::collection(
            ListSubscriptions::execute($request->status(), $request->search())
                ->paginate((int) config('app.pagination.default'))
                ->through(fn (Subscription $subscription) => new AdminSubscriptionResource($subscription, $prices)),
        );
    }
}
