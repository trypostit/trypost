<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\CalculateInboxScore;
use App\Dto\Inbox\InboxFilters;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\InboxScoreResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Get the comment score of the current workspace\'s inbox: how it answered the comments and reviews of the last 7 days (today included, in the user\'s time zone). score (0-100) weighs response_rate (50%, answered comments over comments, capped at 20% for 1-4 comments and 60% for 5-9), response_speed (30%, 100% for a first reply within 2 hours, down to 0% at 48 hours) and consistency (20%, days with comments that got a reply the same day). Mentions and direct messages do not count. score, level and the three metrics are null when no comment arrived in the window. Pass channel_ids for some channels only.')]
class GetInboxScoreTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if (! Platform::inboxModuleEnabled()) {
            return Response::error('The inbox is not enabled.');
        }

        $validated = $request->validate([
            'channel_ids' => ['sometimes', 'nullable', 'array'],
            'channel_ids.*' => ['bail', 'string', 'uuid', Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id)->whereIn('platform', Platform::inboxValues())],
        ]);

        $channelIds = InboxFilters::fromArray(['channels' => data_get($validated, 'channel_ids', [])])->channelIds;

        return Response::structured(
            InboxScoreResource::make(CalculateInboxScore::cached($workspace, $request->user(), $channelIds))->resolve(),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'channel_ids' => $schema->array()->items($schema->string())->description('Only the comments of these channels (social account UUIDs of the current workspace, on a network with an inbox; any other id is refused). Leave it out for every channel.'),
        ];
    }
}
