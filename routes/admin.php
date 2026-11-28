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
use App\Http\Middleware\Admin\EnsureAdminEnabled;
use App\Http\Middleware\Admin\EnsureCanAccessAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth', EnsureAdminEnabled::class, EnsureCanAccessAdmin::class])->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

    Route::get('/users', [UserController::class, 'index'])->name('admin.users.index');
    Route::get('/users/insights', [UserInsightsController::class, 'index'])->name('admin.users.insights.index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('admin.users.show');
    Route::post('/users/{user}/block', [UserBlockController::class, 'store'])->name('admin.users.block.store');
    Route::delete('/users/{user}/block', [UserBlockController::class, 'destroy'])->name('admin.users.block.destroy');
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'store'])->name('admin.users.impersonation.store');

    Route::get('/posts', [PostStatsController::class, 'index'])->name('admin.posts.index');
    Route::get('/posts/failed', [FailedPostController::class, 'index'])->name('admin.posts.failed.index');
    Route::post('/posts/{post}/retry', [PostRetryController::class, 'store'])->name('admin.posts.retry.store');

    Route::get('/networks', [NetworkHealthController::class, 'index'])->name('admin.networks.index');
    Route::get('/quotas', [QuotaController::class, 'index'])->name('admin.quotas.index');

    Route::get('/billing/subscriptions', [SubscriptionController::class, 'index'])->name('admin.billing.subscriptions.index');

    Route::get('/billing/insights', [SignupInsightsController::class, 'index'])->name('admin.billing.insights.index');

    Route::get('/billing/offers', [OfferController::class, 'index'])->name('admin.billing.offers.index');
    Route::post('/billing/offers', [OfferController::class, 'store'])->name('admin.billing.offers.store');
    Route::put('/billing/offers/{offer}', [OfferController::class, 'update'])->name('admin.billing.offers.update');
    Route::delete('/billing/offers/{offer}', [OfferController::class, 'destroy'])->name('admin.billing.offers.destroy');
    Route::post('/billing/offers/{offer}/end', [OfferEndController::class, 'store'])->name('admin.billing.offers.end.store');
});
