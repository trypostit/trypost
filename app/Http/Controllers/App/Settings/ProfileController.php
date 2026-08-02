<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Actions\User\DeleteUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Settings\ProfileDeleteRequest;
use App\Http\Requests\App\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        session()->flash('flash.banner', __('settings.flash.profile_updated'));
        session()->flash('flash.bannerStyle', 'success');

        return to_route('app.profile.edit');
    }

    public function uploadPhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:2048'],
        ]);

        $user = $request->user();
        $user->clearMediaCollection('avatar');
        $user->addMedia($request->file('photo'), 'avatar');
        $user->unsetRelation('media');

        session()->flash('flash.banner', __('settings.flash.photo_updated'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function deletePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->clearMediaCollection('avatar');
        $user->unsetRelation('media');

        session()->flash('flash.banner', __('settings.flash.photo_deleted'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function updateLanguage(Request $request): RedirectResponse
    {
        $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', array_keys(config('languages.available')))],
        ]);

        return back()->withCookie(
            cookie()->forever('locale', $request->locale, '/', config('session.domain'))
        );
    }

    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        if (! DeleteUser::execute($request->user(), $request)) {
            session()->flash('flash.banner', __('settings.flash.delete_failed_billing'));
            session()->flash('flash.bannerStyle', 'danger');

            return to_route('app.profile.edit');
        }

        return redirect('/');
    }
}
