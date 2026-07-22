<?php

declare(strict_types=1);

use App\Http\Controllers\Media\AssetPreviewController;
use Illuminate\Support\Facades\Route;

Route::get('media/asset-preview/{workspace}/{media}', AssetPreviewController::class)
    ->middleware('signed')
    ->name('media.asset-preview.show');

require __DIR__.'/webhook.php';
require __DIR__.'/auth.php';
require __DIR__.'/app.php';
