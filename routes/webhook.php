<?php

declare(strict_types=1);

use App\Enums\SocialAccount\MetaApp;
use App\Http\Controllers\Webhooks\MetaDataDeletionController;
use App\Http\Controllers\Webhooks\MetaDeauthorizeController;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::group([
    'domain' => parse_url(config('app.webhook_url'), PHP_URL_HOST) ?: config('app.webhook_url'),
], function () {
    Route::post('telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');

    // Meta posts these server to server: no session, cookie or CSRF token.
    $metaApps = array_column(MetaApp::cases(), 'value');

    Route::post('meta/{app}/deauthorize', MetaDeauthorizeController::class)
        ->whereIn('app', $metaApps)
        ->withoutMiddleware('web')
        ->name('meta.deauthorize');

    Route::post('meta/{app}/data-deletion', [MetaDataDeletionController::class, 'store'])
        ->whereIn('app', $metaApps)
        ->withoutMiddleware('web')
        ->name('meta.data-deletion.store');

    Route::get('meta/data-deletion/{code}', [MetaDataDeletionController::class, 'show'])
        ->where('code', '[a-z0-9]{32}')
        ->name('meta.data-deletion.show');
});
