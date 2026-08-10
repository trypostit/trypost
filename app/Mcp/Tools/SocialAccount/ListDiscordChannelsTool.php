<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\ListDiscordChannels;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List Discord text/announcement channels the bot can post to for a connected Discord server. Use the returned channel id as platforms[].meta.channel_id when creating or updating a Discord post (required to publish).')]
class ListDiscordChannelsTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'createPost',
            'Not authorized to manage posts.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'account_id' => ['required', 'string', 'uuid'],
        ]);

        $account = SocialAccount::where('workspace_id', $workspace->id)
            ->find(data_get($validated, 'account_id'));

        if (! $account) {
            return Response::error('Social account not found.');
        }

        if ($account->platform !== Platform::Discord) {
            return Response::error('This tool only works with Discord social accounts.');
        }

        try {
            return Response::structured([
                'channels' => ListDiscordChannels::execute($account),
            ]);
        } catch (PlatformUnavailableException $e) {
            return Response::error($e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected Discord social account.'),
        ];
    }
}
