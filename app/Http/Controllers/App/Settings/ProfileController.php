<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Actions\User\DeleteUser;
use App\Enums\Auth\SocialAuthProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Settings\ProfileDeleteRequest;
use App\Http\Requests\App\Settings\ProfileUpdateRequest;
use App\Http\Requests\App\Settings\UpdateLanguageRequest;
use App\Http\Requests\App\Settings\UploadPhotoRequest;
use App\Jobs\PostHog\SyncUser;
use App\Models\User;
use App\Services\PostHogService;
use App\Support\Auth\PasswordRequirements;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/profile/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'hasPassword' => (bool) $user->password,
            'passwordRequirements' => PasswordRequirements::checklist(),
            'connectedAccounts' => $this->connectedAccounts($user),
            'sessions' => $this->sessions($request),
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

    public function uploadPhoto(UploadPhotoRequest $request): RedirectResponse
    {
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

    public function updateLanguage(UpdateLanguageRequest $request): RedirectResponse
    {
        $request->user()->update(['locale' => $request->validated('locale')]);

        if (PostHogService::shouldTrack()) {
            SyncUser::dispatch((string) $request->user()->id);
        }

        return back();
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

    /**
     * @return array<int, array{provider: string, label: string, connected: bool, can_disconnect: bool}>
     */
    private function connectedAccounts(User $user): array
    {
        return collect(SocialAuthProvider::cases())->map(fn (SocialAuthProvider $provider): array => [
            'provider' => $provider->value,
            'label' => $provider->label(),
            'connected' => $user->isConnectedTo($provider),
            'can_disconnect' => $user->isConnectedTo($provider) && $user->canDisconnectFrom($provider),
        ])->values()->all();
    }

    /**
     * @return array<int, array{id: string, ip_address: string|null, user_agent: string|null, last_active: string, is_current: bool}>
     */
    private function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                'is_current' => $session->id === $request->session()->getId(),
            ])->values()->all();
    }
}
