<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\ListPinterestBoards;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\PinterestPublishException;
use App\Exceptions\TokenExpiredException;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List Pinterest boards for a connected Pinterest account. Use the returned board id as platforms[].meta.board_id when creating or updating a Pinterest post (required to publish).')]
class ListPinterestBoardsTool extends Tool
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

        if ($account->platform !== Platform::Pinterest) {
            return Response::error('This tool only works with Pinterest social accounts.');
        }

        try {
            return Response::structured(ListPinterestBoards::execute($account));
        } catch (TokenExpiredException $e) {
            return Response::error($e->getMessage());
        } catch (PinterestPublishException $e) {
            return Response::error($e->userMessage);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected Pinterest social account.'),
        ];
    }
}
