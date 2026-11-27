<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FailedPostController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\NetworkHealthController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\OfferEndController;
use App\Http\Controllers\Admin\PostRetryController;
use App\Http\Controllers\Admin\PostStatsController;
use App\Http\Controllers\Admin\QuotaController;
use App\Http\Controllers\Admin\SignupInsightsController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\UserBlockController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserInsightsController;
use App\Http\Middleware\Admin\EnsureBackofficeAdmin;
use App\Http\Middleware\Admin\EnsureBackofficeEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureBackofficeEnabled::class, EnsureBackofficeAdmin::class])->group(function (): void {
    Route::get('admin', [DashboardController::class, 'index'])->name('admin.dashboard');

    Route::get('admin/users', [UserController::class, 'index'])->name('admin.users.index');
    Route::get('admin/users/insights', [UserInsightsController::class, 'index'])->name('admin.users.insights.index');
    Route::get('admin/users/{user}', [UserController::class, 'show'])->name('admin.users.show');
    Route::post('admin/users/{user}/block', [UserBlockController::class, 'store'])->name('admin.users.block.store');
    Route::delete('admin/users/{user}/block', [UserBlockController::class, 'destroy'])->name('admin.users.block.destroy');
    Route::post('admin/users/{user}/impersonate', [ImpersonationController::class, 'store'])->name('admin.users.impersonation.store');

    Route::get('admin/posts', [PostStatsController::class, 'index'])->name('admin.posts.index');
    Route::get('admin/posts/failed', [FailedPostController::class, 'index'])->name('admin.posts.failed.index');
    Route::post('admin/posts/{post}/retry', [PostRetryController::class, 'store'])->name('admin.posts.retry.store');

    Route::get('admin/networks', [NetworkHealthController::class, 'index'])->name('admin.networks.index');
    Route::get('admin/quotas', [QuotaController::class, 'index'])->name('admin.quotas.index');

    Route::get('admin/billing/subscriptions', [SubscriptionController::class, 'index'])->name('admin.billing.subscriptions.index');

    Route::get('admin/billing/insights', [SignupInsightsController::class, 'index'])->name('admin.billing.insights.index');

    Route::get('admin/billing/offers', [OfferController::class, 'index'])->name('admin.billing.offers.index');
    Route::post('admin/billing/offers', [OfferController::class, 'store'])->name('admin.billing.offers.store');
    Route::put('admin/billing/offers/{offer}', [OfferController::class, 'update'])->name('admin.billing.offers.update');
    Route::delete('admin/billing/offers/{offer}', [OfferController::class, 'destroy'])->name('admin.billing.offers.destroy');
    Route::post('admin/billing/offers/{offer}/end', [OfferEndController::class, 'store'])->name('admin.billing.offers.end.store');
});
