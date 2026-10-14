<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Http\Controllers\App\Controller;
use App\Http\Requests\App\Settings\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        return back()->with('flash.success', __('settings.flash.password_updated'));
    }
}
