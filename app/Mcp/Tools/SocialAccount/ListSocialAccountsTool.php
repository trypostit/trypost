<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Http\Resources\Api\SocialAccountResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List all connected social accounts for the current workspace (LinkedIn, X, Bluesky, Pinterest, Threads, etc.). Each account has an id, platform, display_name, username, connection status, and has_posting_schedule (true when the account has posting times, so posts can use queue).')]
class ListSocialAccountsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $accounts = $request->user()->currentWorkspace
            ->socialAccounts()
            ->get();

        return Response::structured([
            'social_accounts' => SocialAccountResource::collection($accounts)->resolve(),
        ]);
    }
}
