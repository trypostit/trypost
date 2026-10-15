<?php

declare(strict_types=1);

use App\Http\Controllers\WellKnownController;
use Illuminate\Support\Facades\Route;

Route::get('.well-known/{file}', WellKnownController::class)
    ->whereIn('file', config()->collection('trypost.well_known')->keys()->all())
    ->name('well-known');
