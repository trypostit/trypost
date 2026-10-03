<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Concerns;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait EnsuresChannelInCurrentWorkspace
{
    private function ensureCurrentWorkspace(Request $request, SocialAccount $account): void
    {
        abort_unless($account->workspace_id === $request->user()->current_workspace_id, Response::HTTP_NOT_FOUND);
    }
}
