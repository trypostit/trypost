<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Actions\User\EnsurePersonalAccount;
use App\Actions\Workspace\DeleteWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Settings\ProfileDeleteRequest;
use App\Http\Requests\App\Settings\ProfileUpdateRequest;
use App\Models\Account;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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
        $user = $request->user();

        $account = $user->account;
        $isOwner = $user->isAccountOwner();
        $workspaceMedia = collect();

        // Local cleanup first so a Stripe failure cannot leave members stranded
        // on an account that partially failed to delete. Cancel billing only
        // after workspaces/members are settled, then remove the account row.
        DB::transaction(function () use ($user, $account, $isOwner, &$workspaceMedia) {
            $user->update(['current_workspace_id' => null]);

            $workspaces = $isOwner && $account
                ? Workspace::query()->where('account_id', $account->id)->get()
                : Workspace::query()->where('user_id', $user->id)->get();

            foreach ($workspaces as $workspace) {
                foreach ($workspace->members as $member) {
                    if ($member->id !== $user->id && $member->current_workspace_id === $workspace->id) {
                        $otherWorkspace = $member->workspaces()
                            ->where('workspaces.id', '!=', $workspace->id)
                            ->where('workspaces.account_id', $workspace->account_id)
                            ->first()
                            ?? $member->workspaces()
                                ->where('workspaces.id', '!=', $workspace->id)
                                ->first();

                        $member->update(['current_workspace_id' => $otherWorkspace?->id]);
                    }
                }

                $workspaceMedia = $workspaceMedia->merge($workspace->media()->get());

                Media::query()
                    ->where('mediable_type', Relation::getMorphAlias(Workspace::class))
                    ->where('mediable_id', $workspace->id)
                    ->delete();

                $workspace->posts()->delete();
                $workspace->socialAccounts()->delete();
                $workspace->signatures()->delete();
                $workspace->labels()->delete();
                $workspace->members()->detach();
                $workspace->delete();
            }

            $user->workspaces()->detach();

            if ($account && $isOwner) {
                EnsurePersonalAccount::rehomeAccountMembers($account, $user->id);
            }
        });

        DeleteWorkspace::deleteOrphanedMediaFiles($workspaceMedia->values());

        if ($account && $isOwner) {
            try {
                if ($account->subscribed(Account::SUBSCRIPTION_NAME)) {
                    $account->subscription(Account::SUBSCRIPTION_NAME)->cancelNow();
                }
            } catch (Throwable $e) {
                Log::warning('Failed to cancel Stripe subscription during account delete', [
                    'account_id' => $account->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $account->subscriptions()->delete();
            $account->delete();
        }

        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
