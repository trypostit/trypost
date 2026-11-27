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
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(IndexSubscriptionRequest $request): Response
    {
        $dates = $request->dates();
        $prices = SubscriptionPlans::byPrice();
        $subscriptions = ListSubscriptions::execute($request->status(), $request->search())
            ->paginate((int) config('app.pagination.default'));

        return Inertia::render('admin/billing/subscriptions/Index', [
            'overview' => fn () => BuildSubscriptionOverview::execute($dates['start'], $dates['end'], $request->timezone()),
            'netMrr' => Inertia::defer(fn (): ?array => StripeNetMrr::read()),
            'churn' => fn () => BuildSubscriptionChurn::execute($dates['start'], $dates['end'], $request->timezone()),
            'subscriptions' => Inertia::scroll(fn () => AdminSubscriptionResource::collection(
                $subscriptions->through(fn (Subscription $subscription) => new AdminSubscriptionResource($subscription, $prices)),
            )),
            'statusCounts' => collect(SubscriptionStatusFilter::cases())
                ->mapWithKeys(fn (SubscriptionStatusFilter $status): array => [$status->value => ListSubscriptions::execute($status, $request->search())->count()])
                ->all(),
            'range' => $dates,
            'ranges' => IndexSubscriptionRequest::RANGES,
            'bounds' => [
                'min' => IndexSubscriptionRequest::earliest($request->timezone())->toDateString(),
                'max' => now($request->timezone())->toDateString(),
            ],
            'filters' => [
                'range' => $request->range(),
                'start' => $dates['start'],
                'end' => $dates['end'],
                'status' => $request->status()->value,
                'search' => $request->search() ?? '',
            ],
        ]);
    }
}
