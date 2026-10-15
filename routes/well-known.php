<?php

declare(strict_types=1);

use App\Http\Controllers\WellKnownController;
use Illuminate\Support\Facades\Route;

$wellKnownFiles = config()->collection('trypost.well_known')->keys();

if ($wellKnownFiles->isNotEmpty()) {
    Route::get('.well-known/{file}', WellKnownController::class)
        ->whereIn('file', $wellKnownFiles->all())
        ->withoutMiddleware('web')
        ->name('well-known');
}
