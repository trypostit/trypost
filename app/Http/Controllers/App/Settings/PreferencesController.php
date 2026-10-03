<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\Settings\UpdatePreferencesRequest;
use App\Support\Timezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/profile/Preferences', [
            'timezones' => Timezone::options(),
        ]);
    }

    public function update(UpdatePreferencesRequest $request): RedirectResponse|HttpResponse
    {
        $request->user()->update($request->validated());

        return $request->wantsJson() ? response()->noContent() : back();
    }
}
