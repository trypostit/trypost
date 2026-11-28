<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Subscriptions\BuildSubscriptionChurn;
use App\Actions\Admin\Subscriptions\BuildSubscriptionOverview;
use App\Actions\Admin\Subscriptions\ListSubscriptions;
use App\Enums\Analytics\DateRangePreset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subscription\IndexSubscriptionRequest;
use App\Http\Resources\Admin\AdminSubscriptionResource;
use App\Support\Admin\StripeNetMrr;
use App\Support\Admin\SubscriptionHistory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(IndexSubscriptionRequest $request): Response
    {
        $range = $request->dateRange();

        return Inertia::render('admin/billing/subscriptions/Index', [
            'overview' => fn (): array => BuildSubscriptionOverview::execute($range, $this->history()),
            'netMrr' => Inertia::defer(fn (): array => StripeNetMrr::read()),
            'churn' => fn (): array => BuildSubscriptionChurn::execute($range, $this->history()),
            'subscriptions' => Inertia::scroll(fn (): AnonymousResourceCollection => AdminSubscriptionResource::collection(
                ListSubscriptions::execute($request->status(), $request->search())->paginate((int) config('app.pagination.default')),
            )),
            'statusCounts' => fn (): array => ListSubscriptions::statusCounts($request->search()),
            'ranges' => DateRangePreset::backoffice(),
            ...$request->rangeProps(),
            'filters' => [
                ...$request->rangeFilters(),
                'status' => $request->status()->value,
                'search' => $request->search() ?? '',
            ],
        ]);
    }

    /**
     * Every subscription that ever billed, read once for the overview and the
     * churn, and only when a prop that needs it is sent.
     */
    private function history(): SubscriptionHistory
    {
        return once(fn (): SubscriptionHistory => SubscriptionHistory::read());
    }
}
