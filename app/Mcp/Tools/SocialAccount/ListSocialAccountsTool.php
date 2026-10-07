<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Http\Resources\Api\SocialAccountResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the connected social accounts for the current workspace (LinkedIn, X, Bluesky, Pinterest, Threads, etc.). Each account has an id, platform, display_name, username, connection status (connected, token_expired or disconnected), timezone (the channel time zone: posting times and queue slots are in it), posting_goal (posts per week, or null), has_posting_schedule (true when the account has posting times, so posts can use queue) max_content_length (the text limit of this account), long_posts and verified_badge. On X, long_posts is true for an account with a paid X subscription (Basic, Premium or Premium+) or a verified organization, and it may post up to 25000 characters; every other X account is limited to 280. verified_badge is the checkmark X shows (blue, business or government, else null); a badge alone does not mean long posts, so read long_posts or max_content_length. Both are refreshed when the account connects and once a day. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListSocialAccountsTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $accounts = $workspace
            ->socialAccounts()
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'social_accounts' => SocialAccountResource::collection($accounts->items())->resolve(),
            'total' => $accounts->total(),
            'per_page' => $accounts->perPage(),
            'current_page' => $accounts->currentPage(),
            'last_page' => $accounts->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
