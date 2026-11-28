<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\User\ListUsersForAdmin;
use App\Actions\User\Profile\BuildUserActivity;
use App\Actions\User\Profile\BuildUserBilling;
use App\Actions\User\Profile\BuildUserIntegrations;
use App\Actions\User\Profile\BuildUserOverview;
use App\Actions\User\Profile\BuildUserPosts;
use App\Actions\User\Profile\BuildUserWorkspaces;
use App\Enums\User\UserProfileTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\IndexUserRequest;
use App\Http\Requests\Admin\User\ShowUserRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(IndexUserRequest $request): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => Inertia::scroll(fn () => AdminUserResource::collection(
                ListUsersForAdmin::execute($request->search(), $request->status())->paginate((int) config('app.pagination.default')),
            )),
            'filters' => [
                'search' => (string) $request->search(),
                'status' => $request->status()->value,
            ],
        ]);
    }

    /**
     * The user's profile, one tab at a time: only the open tab's data is built.
     */
    public function show(ShowUserRequest $request, User $user): Response
    {
        $tab = $request->tab();
        $user->loadMissing(['account.plan', 'account.subscriptions'])->loadCount('workspaces');

        return Inertia::render('admin/users/Show', [
            'user' => AdminUserResource::make($user),
            'tab' => $tab->value,
            'data' => match ($tab) {
                UserProfileTab::Overview => BuildUserOverview::execute($user, $request->user()->timezone),
                UserProfileTab::Workspaces => BuildUserWorkspaces::execute($user),
                UserProfileTab::Posts => BuildUserPosts::execute($user),
                UserProfileTab::Integrations => BuildUserIntegrations::execute($user),
                UserProfileTab::Billing => BuildUserBilling::execute($user),
                UserProfileTab::Activity => BuildUserActivity::execute($user),
            },
        ]);
    }
}
