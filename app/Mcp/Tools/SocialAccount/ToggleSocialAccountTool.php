<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\ToggleSocialAccount;
use App\Http\Resources\Api\SocialAccountResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Toggle a social account active/inactive. When inactive, the account is skipped during scheduled publishing. Returns the updated account.')]
class ToggleSocialAccountTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'manageAccounts',
            'Not authorized to manage social accounts.',
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

        $account = ToggleSocialAccount::execute($account);

        return Response::structured((new SocialAccountResource($account))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the social account to toggle.'),
        ];
    }
}
