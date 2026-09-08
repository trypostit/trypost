<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Http\Controllers\App\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class UsageController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        $account = $request->user()->account;

        $totalSocialAccounts = 0;
        $totalMembers = $account->users()->count();

        foreach ($account->workspaces as $workspace) {
            $totalSocialAccounts += $workspace->socialAccounts()->count();
        }

        return Inertia::render('settings/account/Usage', [
            'usage' => [
                'workspaceCount' => $account->workspaces->count(),
                'socialAccountCount' => $totalSocialAccounts,
                'memberCount' => $totalMembers,
            ],
        ]);
    }
}
