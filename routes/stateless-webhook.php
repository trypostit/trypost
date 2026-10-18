<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Network deliveries signed by their sender (Meta). Registered
| outside the web group (bootstrap/app.php): no session row, no cookie and no
| CSRF check per delivery; the controllers check the signature instead.
*/
Route::group([
    'domain' => parse_url(config('app.webhook_url'), PHP_URL_HOST) ?: config('app.webhook_url'),
], function () {
    Route::get('webhooks/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
    Route::post('webhooks/meta', [MetaWebhookController::class, 'store'])->name('webhooks.meta');
});
