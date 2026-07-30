<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\Media\DeleteWorkspaceMedia;
use App\Actions\User\EnsurePersonalAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Settings\ProfileDeleteRequest;
use App\Http\Requests\App\Settings\ProfileUpdateRequest;
use App\Models\Account;
use App\Models\Invite;
use App\Models\Media;
use App\Models\User;
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
        $mediaPaths = [];

        // Local cleanup first so a Stripe failure cannot leave members stranded
        // on an account that partially failed to delete. Cancel billing only
        // after workspaces/members are settled, then remove the account row.
        DB::transaction(function () use ($user, $account, $isOwner, &$mediaPaths) {
            $user->update(['current_workspace_id' => null]);

            $workspaces = $isOwner && $account
                ? Workspace::query()->where('account_id', $account->id)->get()
                : Workspace::query()->where('user_id', $user->id)->get();

            foreach ($workspaces as $workspace) {
                foreach ($workspace->members as $member) {
                    if ($member->id !== $user->id && $member->current_workspace_id === $workspace->id) {
                        // Same-account only; rehomeAccountMembers restores a
                        // personal current after the shared account is cleared.
                        $otherWorkspace = $member->workspaces()
                            ->where('workspaces.id', '!=', $workspace->id)
                            ->where('workspaces.account_id', $workspace->account_id)
                            ->first();

                        $member->update(['current_workspace_id' => $otherWorkspace?->id]);
                    }
                }

                $mediaPaths = [
                    ...$mediaPaths,
                    ...DeleteWorkspaceMedia::purgeRecords($workspace),
                ];

                $workspace->posts()->delete();
                $workspace->socialAccounts()->delete();
                $workspace->signatures()->delete();
                $workspace->labels()->delete();
                $workspace->members()->detach();
                $workspace->delete();
            }

            $user->workspaces()->detach();

            if ($account && $isOwner) {
                // Drop invites with the workspaces so a Stripe cancel failure
                // cannot leave unique(email, account_id) rows blocking re-invites.
                Invite::query()->where('account_id', $account->id)->delete();

                EnsurePersonalAccount::rehomeAccountMembers($account, $user->id);
            }
        });

        // Workspace media rows are already gone — flush files before billing
        // so a Stripe cancel failure cannot leave permanent storage orphans.
        DeleteOrphanedMediaFiles::execute($mediaPaths);
        $mediaPaths = [];

        if ($account && $isOwner) {
            try {
                if ($account->subscribed(Account::SUBSCRIPTION_NAME)) {
                    $account->subscription(Account::SUBSCRIPTION_NAME)->cancelNow();
                }

                $account->subscriptions()->delete();
                $account->delete();
            } catch (Throwable $e) {
                Log::warning('Failed to cancel Stripe subscription during account delete', [
                    'account_id' => $account->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                // Workspaces are already gone locally — drop Stripe quantity so
                // a stuck cancel cannot keep billing the old seat count.
                $account->syncWorkspaceQuantity();

                // Keep local Stripe customer/subscription linkage so billing can
                // still be cancelled or retried. The owner can retry deletion.
                session()->flash('flash.banner', __('settings.flash.delete_failed_billing'));
                session()->flash('flash.bannerStyle', 'danger');

                return to_route('app.profile.edit');
            }
        }

        $userMediaQuery = Media::query()
            ->where('mediable_type', Relation::getMorphAlias(User::class))
            ->where('mediable_id', $user->id);

        $mediaPaths = $userMediaQuery->pluck('path')->all();
        $userMediaQuery->delete();

        DeleteOrphanedMediaFiles::execute($mediaPaths);

        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
