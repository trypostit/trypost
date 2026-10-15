<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\CreatePinterestBoard;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\PinterestPublishException;
use App\Exceptions\TokenExpiredException;
use App\Http\Resources\Api\PinterestBoardResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Social\PinterestBoardFailure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
#[Description('Create a board on a connected Pinterest account. Returns the board id, name and cover_url; use the id as meta.board_id when creating a Pinterest post.')]
class CreatePinterestBoardTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if (is_string($request->get('name'))) {
            $request->merge(['name' => trim($request->get('name'))]);
        }

        $account = SocialAccount::where('workspace_id', $workspace->id)
            ->find(data_get($request->validate(['account_id' => ['required', 'string', 'uuid']]), 'account_id'));

        if (! $account) {
            return Response::error('Social account not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'view', $account, 'Social account not found.')) {
            return $denied;
        }

        if ($account->platform !== Platform::Pinterest) {
            return Response::error('This tool only works with Pinterest social accounts.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        try {
            $board = CreatePinterestBoard::execute($account, ['name' => (string) data_get($validated, 'name')]);
        } catch (TokenExpiredException|PinterestPublishException|ConnectionException $e) {
            return Response::error(PinterestBoardFailure::message($e));
        }

        return Response::structured(PinterestBoardResource::make($board)->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected Pinterest social account.'),
            'name' => $schema->string()->required()->description('The board name (at most 50 characters).'),
        ];
    }
}
