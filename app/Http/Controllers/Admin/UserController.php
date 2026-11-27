<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ListUsers;
use App\Actions\Admin\UserProfile\BuildUserActivity;
use App\Actions\Admin\UserProfile\BuildUserBilling;
use App\Actions\Admin\UserProfile\BuildUserIntegrations;
use App\Actions\Admin\UserProfile\BuildUserOverview;
use App\Actions\Admin\UserProfile\BuildUserPosts;
use App\Actions\Admin\UserProfile\BuildUserWorkspaces;
use App\Enums\Admin\UserTab;
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
                ListUsers::execute($request->search(), $request->status())->paginate((int) config('app.pagination.default')),
            )),
            'filters' => [
                'search' => $request->search() ?? '',
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
                UserTab::Overview => BuildUserOverview::execute($user),
                UserTab::Workspaces => BuildUserWorkspaces::execute($user),
                UserTab::Posts => BuildUserPosts::execute($user),
                UserTab::Integrations => BuildUserIntegrations::execute($user),
                UserTab::Billing => BuildUserBilling::execute($user),
                UserTab::Activity => BuildUserActivity::execute($user),
            },
        ]);
    }
}
